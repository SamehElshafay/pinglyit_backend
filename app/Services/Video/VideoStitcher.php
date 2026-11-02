<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Joins rendered shots into one film, and pulls stills out of them.
 *
 * Shots can't just be byte-concatenated: a provider may return a slightly
 * different size, frame rate or audio layout from one shot to the next,
 * and a shot rendered without audio has no audio stream at all — the
 * concat demuxer would then drop sound from the whole film. So every shot
 * is first normalised to one exact format (same size, 24fps, yuv420p,
 * 48kHz stereo AAC, silence added where a shot had none), and only then
 * joined, losslessly.
 *
 * Needs ffmpeg + ffprobe on the server (config pingly.video.ffmpeg/ffprobe).
 */
class VideoStitcher
{
    public function available(): bool
    {
        try {
            return Process::timeout(10)->run([config('pingly.video.ffmpeg'), '-version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $shotPaths  absolute paths, in order
     */
    public function stitch(array $shotPaths, string $outputPath, int $width, int $height): void
    {
        $workDir = dirname($outputPath).'/_work';
        @mkdir($workDir, 0775, true);

        $normalised = [];
        foreach ($shotPaths as $i => $path) {
            $out = sprintf('%s/n%03d.mp4', $workDir, $i);
            $this->normalise($path, $out, $width, $height);
            $normalised[] = $out;
        }

        $list = $workDir.'/list.txt';
        file_put_contents($list, collect($normalised)->map(fn ($p) => "file '".str_replace("'", "'\\''", $p)."'")->implode("\n"));

        $this->run([
            config('pingly.video.ffmpeg'), '-y', '-f', 'concat', '-safe', '0', '-i', $list,
            '-c', 'copy', '-movflags', '+faststart', $outputPath,
        ], 300);

        foreach ([...$normalised, $list] as $f) {
            @unlink($f);
        }
        @rmdir($workDir);
    }

    /** One frame, as JPEG — the film's poster, or a shot's identity anchor. */
    public function still(string $videoPath, string $imagePath, float $atSeconds): void
    {
        $this->run([
            config('pingly.video.ffmpeg'), '-y', '-ss', (string) $atSeconds, '-i', $videoPath,
            '-frames:v', '1', '-q:v', '2', $imagePath,
        ], 60);
    }

    /**
     * A copy that fits a face-swap service: H.264 MP4, 24 fps, longest side
     * ≤ 1280 (720p), at most $maxSeconds long, small enough to upload.
     */
    public function fitForFaceSwap(string $in, string $out, float $maxSeconds): void
    {
        $this->run([
            config('pingly.video.ffmpeg'), '-y', '-i', $in, '-t', (string) $maxSeconds,
            '-vf', "scale='if(gt(iw,ih),min(1280,iw),-2)':'if(gt(iw,ih),-2,min(1280,ih))',fps=24,format=yuv420p",
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '23', '-maxrate', '4M', '-bufsize', '8M',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart',
            $out,
        ], 600);
    }

    public function duration(string $videoPath): ?float
    {
        $result = Process::timeout(30)->run([
            config('pingly.video.ffprobe'), '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $videoPath,
        ]);

        return $result->successful() && is_numeric(trim($result->output())) ? (float) trim($result->output()) : null;
    }

    private function normalise(string $in, string $out, int $width, int $height): void
    {
        $video = "[0:v]scale={$width}:{$height}:force_original_aspect_ratio=decrease,"
            ."pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=24,format=yuv420p[v]";

        $cmd = [config('pingly.video.ffmpeg'), '-y', '-i', $in];

        if ($this->hasAudio($in)) {
            $cmd = [...$cmd, '-filter_complex', $video.';[0:a]aresample=48000,aformat=channel_layouts=stereo[a]'];
        } else {
            $cmd = [...$cmd, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo',
                '-filter_complex', $video.';[1:a]anull[a]', '-shortest'];
        }

        $this->run([...$cmd,
            '-map', '[v]', '-map', '[a]',
            '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-profile:v', 'high',
            '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
            '-video_track_timescale', '24000',
            $out,
        ], 600);
    }

    private function hasAudio(string $path): bool
    {
        $result = Process::timeout(30)->run([
            config('pingly.video.ffprobe'), '-v', 'error', '-select_streams', 'a',
            '-show_entries', 'stream=index', '-of', 'csv=p=0', $path,
        ]);

        return $result->successful() && trim($result->output()) !== '';
    }

    /** @param array<int, string> $cmd */
    private function run(array $cmd, int $timeout): void
    {
        $result = Process::timeout($timeout)->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException('ffmpeg failed: '.mb_substr($result->errorOutput(), -800));
        }
    }
}
