<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

/**
 * An in-memory stream that refuses to grow past a byte cap.
 *
 * Used as the HTTP client's `sink`. Guzzle's cURL handler writes the body
 * through it chunk by chunk and returns the byte count to libcurl; once the
 * cap is reached this reports 0 bytes written, which makes libcurl abort the
 * transfer (CURLE_WRITE_ERROR) instead of buffering an unbounded body.
 *
 * Why not the alternatives: throwing from Guzzle's `progress` callback does
 * not stop the transfer (measured: the download ran to completion anyway),
 * and the raw CURLOPT_MAXFILESIZE_LARGE option is deprecated by Guzzle 7.12+
 * and slated for rejection in 8.0.
 *
 * This is a PHP userland stream wrapper, so it needs no dependency.
 */
final class CappedBuffer
{
    public const PROTOCOL = 'fancy-heuristics-capped';

    /** @var resource|null set by PHP for stream wrappers */
    public $context;

    private string $data = '';

    private int $pos = 0;

    private int $max = 0;

    private ?\stdClass $state = null;

    /**
     * Open a capped buffer. Returns the stream and a state object whose
     * `exceeded` flag turns true once a write was refused.
     *
     * @return array{0: resource, 1: \stdClass}
     */
    public static function open(int $maxBytes): array
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $state = new \stdClass;
        $state->exceeded = false;

        $context = stream_context_create([self::PROTOCOL => ['max' => $maxBytes, 'state' => $state]]);
        $stream = fopen(self::PROTOCOL.'://buffer', 'w+b', false, $context);

        if ($stream === false) {
            throw new \RuntimeException('Could not open the capped response buffer.');
        }

        return [$stream, $state];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $opts = stream_context_get_options($this->context)[self::PROTOCOL] ?? [];
        $this->max = (int) ($opts['max'] ?? 0);
        $this->state = $opts['state'] ?? null;

        return $this->max > 0 && $this->state instanceof \stdClass;
    }

    public function stream_write(string $chunk): int
    {
        $room = $this->max - strlen($this->data);

        if (strlen($chunk) > $room) {
            $this->state->exceeded = true;

            return 0;
        }

        $this->data .= $chunk;
        $this->pos = strlen($this->data);

        return strlen($chunk);
    }

    public function stream_read(int $count): string
    {
        $out = substr($this->data, $this->pos, $count);
        $this->pos += strlen($out);

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->pos + $offset,
            SEEK_END => strlen($this->data) + $offset,
            default => -1,
        };

        if ($target < 0 || $target > strlen($this->data)) {
            return false;
        }

        $this->pos = $target;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->data), 'mode' => 0100666];
    }

    public function stream_close(): void
    {
        $this->data = '';
    }
}
