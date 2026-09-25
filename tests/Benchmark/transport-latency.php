<?php

/**
 * ADR 0002 spike, exit criterion 3: unary Search latency per gRPC transport.
 *
 *   docker compose up -d weaviate
 *   GRPC=1 PHP_VERSION=8.4 DEBIAN=trixie bin/php tests/Benchmark/transport-latency.php [iterations]
 *
 * Prints p50/p95/mean per transport. Target: curl p50 within 1.5x of ext-grpc.
 */

declare(strict_types=1);

use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Proto\V1\MetadataRequest;
use Weaviate\Client\Proto\V1\NearVector;
use Weaviate\Client\Proto\V1\PropertiesRequest;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Proto\V1\Vectors;
use Weaviate\Client\Proto\V1\Vectors\VectorType;
use Weaviate\Client\Transport\Grpc\CurlGrpcTransport;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * @param list<float> $samples
 */
function percentile(array $samples, float $p): float
{
    sort($samples);

    return $samples[(int) floor((count($samples) - 1) * $p)] ?? \NAN;
}

$iterations = (int) ($argv[1] ?? 500);
$host = getenv('WEAVIATE_HOST') ?: 'localhost';
$dimensions = 128;
$objects = 200;

$connect = static fn(GrpcTransportChoice $t): WeaviateClient => Weaviate::connectToLocal(
    host: $host,
    additionalConfig: new AdditionalConfig(grpcTransport: $t),
);

// Setup: one collection with random vectors, inserted over REST.
$setup = $connect(GrpcTransportChoice::Curl);
$collection = 'Bench_' . bin2hex(random_bytes(4));
$setup->restTransport()->requestExpecting('POST', '/schema', 'create', body: [
    'class' => $collection,
    'vectorizer' => 'none',
    'properties' => [['name' => 'title', 'dataType' => ['text']], ['name' => 'n', 'dataType' => ['int']]],
]);
$randomVector = static fn(): array => array_map(static fn() => mt_rand() / mt_getrandmax(), range(1, $dimensions));
$batch = [];
for ($i = 0; $i < $objects; ++$i) {
    $batch[] = ['class' => $collection, 'properties' => ['title' => "object $i", 'n' => $i], 'vector' => $randomVector()];
}
$setup->restTransport()->requestExpecting('POST', '/batch/objects', 'batch', body: ['objects' => $batch]);

$requests = [
    'fetchObjects(limit 10)' => new SearchRequest([
        'collection' => $collection,
        'limit' => 10,
        'uses_127_api' => true,
        'properties' => new PropertiesRequest(['non_ref_properties' => ['title', 'n']]),
        'metadata' => new MetadataRequest(['uuid' => true]),
    ]),
    'nearVector(limit 10)' => new SearchRequest([
        'collection' => $collection,
        'limit' => 10,
        'uses_127_api' => true,
        'near_vector' => new NearVector(['vectors' => [new Vectors([
            'vector_bytes' => pack('g*', ...$randomVector()),
            'type' => VectorType::VECTOR_TYPE_SINGLE_FP32,
        ])]]),
        'properties' => new PropertiesRequest(['non_ref_properties' => ['title', 'n']]),
        'metadata' => new MetadataRequest(['uuid' => true, 'distance' => true]),
    ]),
];

$transports = ['curl' => GrpcTransportChoice::Curl];
if (ExtGrpcTransport::isSupported()) {
    $transports['ext-grpc'] = GrpcTransportChoice::ExtGrpc;
}


$curl = curl_version();
$libcurl = is_array($curl) && is_string($curl['version'] ?? null) ? $curl['version'] : '?';
printf(
    "PHP %s, libcurl %s (connection reuse: %s), ext-grpc: %s, ext-protobuf: %s, %d iterations\n\n",
    PHP_VERSION,
    $libcurl,
    CurlGrpcTransport::canReuseConnections() ? 'yes' : 'no',
    ExtGrpcTransport::isSupported() ? phpversion('grpc') : 'no',
    extension_loaded('protobuf') ? phpversion('protobuf') : 'no (pure PHP)',
    $iterations,
);
printf("%-24s %-9s %9s %9s %9s\n", 'query', 'transport', 'p50 ms', 'p95 ms', 'mean ms');

$results = [];
try {
    foreach ($requests as $label => $request) {
        foreach ($transports as $name => $choice) {
            $client = $connect($choice);
            $grpc = $client->grpcTransport();
            $metadata = $client->grpcMetadata();
            for ($i = 0; $i < 20; ++$i) { // warm-up
                $grpc->unary('/weaviate.v1.Weaviate/Search', $request, SearchReply::class, 10.0, $metadata);
            }
            $samples = [];
            for ($i = 0; $i < $iterations; ++$i) {
                $start = hrtime(true);
                $grpc->unary('/weaviate.v1.Weaviate/Search', $request, SearchReply::class, 10.0, $metadata);
                $samples[] = (hrtime(true) - $start) / 1e6;
            }
            $client->close();
            $results[$label][$name] = $p50 = percentile($samples, 0.5);
            printf("%-24s %-9s %9.3f %9.3f %9.3f\n", $label, $name, $p50, percentile($samples, 0.95), array_sum($samples) / count($samples));
        }
    }
} finally {
    $setup->restTransport()->request('DELETE', RestTransport::path('schema', $collection));
}

if (isset($transports['ext-grpc'])) {
    echo "\n";
    foreach ($results as $label => $byTransport) {
        $ratio = $byTransport['curl'] / ($byTransport['ext-grpc'] ?? \NAN);
        printf("%-24s curl/ext-grpc p50 ratio: %.2fx %s\n", $label, $ratio, $ratio <= 1.5 ? '(PASS <= 1.5x)' : '(FAIL > 1.5x)');
    }
}
