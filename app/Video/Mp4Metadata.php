<?php

namespace App\Video;

use Illuminate\Validation\ValidationException;

class Mp4Metadata
{
    /** @return array{duration_seconds: int, width: int, height: int} */
    public function read(string $path): array
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            $this->invalid();
        }
        try {
            $size = (int) fstat($file)['size'];
            $metadata = ['duration_seconds' => 0, 'width' => 0, 'height' => 0];
            $count = 0;
            $hasMedia = false;
            foreach ($this->boxes($file, 0, $size, $count) as $box) {
                if ($box['type'] === 'mdat' && $box['end'] > $box['data']) {
                    $hasMedia = true;
                }
                if ($box['type'] === 'moov') {
                    $this->movie($file, $box['data'], $box['end'], $metadata, $count);
                }
            }
            if (! $hasMedia || $metadata['duration_seconds'] < 1 || $metadata['duration_seconds'] > 86400 || $metadata['width'] < 1 || $metadata['height'] < 1) {
                $this->invalid();
            }

            return $metadata;
        } finally {
            fclose($file);
        }
    }

    /** @param resource $file
     * @return \Generator<int, array{type: string, data: int, end: int}>
     */
    private function boxes($file, int $start, int $end, int &$count): \Generator
    {
        while ($start < $end) {
            if (++$count > 10000 || $end - $start < 8) {
                $this->invalid();
            }
            $header = $this->bytes($file, $start, 8);
            $size = unpack('N', substr($header, 0, 4))[1];
            $headerSize = 8;
            if ($size === 1) {
                $extended = unpack('Nhigh/Nlow', $this->bytes($file, $start + 8, 8));
                if ($extended['high'] > 2) {
                    $this->invalid();
                }
                $size = $extended['high'] * 4294967296 + $extended['low'];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $start;
            }
            if ($size < $headerSize || $start + $size > $end) {
                $this->invalid();
            }
            yield ['type' => substr($header, 4, 4), 'data' => $start + $headerSize, 'end' => $start + $size];
            $start += $size;
        }
    }

    /** @param resource $file
     * @param  array{duration_seconds: int, width: int, height: int}  $metadata
     */
    private function movie($file, int $start, int $end, array &$metadata, int &$count, int $depth = 0): void
    {
        if ($depth > 6) {
            $this->invalid();
        }
        foreach ($this->boxes($file, $start, $end, $count) as $box) {
            $length = $box['end'] - $box['data'];
            if ($box['type'] === 'mvhd') {
                if ($length < 20) {
                    $this->invalid();
                }
                $version = ord($this->bytes($file, $box['data'], 1));
                if (! in_array($version, [0, 1], true) || ($version === 1 && $length < 32)) {
                    $this->invalid();
                }
                $offset = $box['data'] + ($version === 1 ? 20 : 12);
                $timescale = unpack('N', $this->bytes($file, $offset, 4))[1];
                $duration = unpack('N', $this->bytes($file, $offset + 4, 4))[1];
                if ($version === 1) {
                    if ($duration !== 0) {
                        $this->invalid();
                    }
                    $duration = unpack('N', $this->bytes($file, $offset + 8, 4))[1];
                }
                $metadata['duration_seconds'] = $timescale > 0 ? (int) ceil($duration / $timescale) : 0;
            } elseif (in_array($box['type'], ['trak', 'mdia', 'minf', 'stbl'], true)) {
                $this->movie($file, $box['data'], $box['end'], $metadata, $count, $depth + 1);
            } elseif ($box['type'] === 'stsd') {
                if ($length < 8) {
                    $this->invalid();
                }
                foreach ($this->boxes($file, $box['data'] + 8, $box['end'], $count) as $entry) {
                    if (! in_array($entry['type'], ['avc1', 'avc3', 'mp4a'], true)) {
                        $this->invalid();
                    }
                    if ($entry['type'] !== 'mp4a') {
                        if ($entry['end'] - $entry['data'] < 78) {
                            $this->invalid();
                        }
                        $dimensions = unpack('nwidth/nheight', $this->bytes($file, $entry['data'] + 24, 4));
                        $metadata['width'] = $dimensions['width'];
                        $metadata['height'] = $dimensions['height'];
                    }
                }
            }
        }
    }

    /** @param resource $file */
    private function bytes($file, int $offset, int $length): string
    {
        if (fseek($file, $offset) !== 0) {
            $this->invalid();
        }
        $bytes = fread($file, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            $this->invalid();
        }

        return $bytes;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['video' => 'ارفع فيديو MP4 صالحاً بترميز H.264 وصوت AAC. هذا الوضع لا يحوّل صيغ الفيديو؛ صدّر الملف بهذه الإعدادات من جهازك.']);
    }
}
