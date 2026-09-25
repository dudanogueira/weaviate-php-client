<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * A REST call returned a status code the operation doesn't expect. Carries the status and the decoded body,
 * and includes the server's error message in getMessage().
 */
class UnexpectedStatusCodeException extends WeaviateException
{
    /**
     * @param mixed $body decoded JSON body, or the raw string when it isn't JSON
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        $detail = self::serverMessage($body);
        parent::__construct(
            \sprintf('%s: unexpected status code %d%s', $message, $statusCode, $detail !== null ? ' (' . $detail . ')' : ''),
            $statusCode,
            $previous,
        );
    }

    /**
     * The human-readable part of a Weaviate error body: `{"error":[{"message":…}]}`, `{"message":…}`, or text.
     */
    public static function serverMessage(mixed $body): ?string
    {
        if (\is_string($body)) {
            $text = trim($body);

            return $text === '' ? null : (\strlen($text) > 300 ? substr($text, 0, 300) . '…' : $text);
        }
        if (!\is_array($body)) {
            return null;
        }
        if (\is_string($body['message'] ?? null)) {
            return $body['message'];
        }
        $errors = $body['error'] ?? null;
        if (\is_array($errors)) {
            $messages = [];
            foreach ($errors as $error) {
                if (\is_array($error) && \is_string($error['message'] ?? null)) {
                    $messages[] = $error['message'];
                }
            }

            return $messages === [] ? null : implode('; ', $messages);
        }

        return null;
    }
}
