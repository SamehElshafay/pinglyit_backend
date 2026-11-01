<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Every Video Studio render across all clients — the admin's support view:
 * what each client ordered, where it is, what it cost Pingly against what
 * the client paid, and the film itself when there is one.
 */
class VideoProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = VideoProject::query()
            ->with(['company:id,name', 'shots:id,video_project_id,status,duration,raw_cost,billed_amount'])
            ->when($request->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25);

        return response()->json($projects->through(function (VideoProject $p) {
            $signed = fn (string $kind) => URL::temporarySignedRoute('video.media', now()->addHours(6), ['project' => $p->id, 'kind' => $kind]);

            return [
                'id' => $p->id,
                'client' => $p->company?->name,
                'company_id' => $p->company_id,
                'title' => $p->title,
                'prompt' => $p->prompt,
                'model' => $p->model,
                'format' => "{$p->aspect_ratio} · {$p->resolution}",
                'duration' => (int) ($p->output_duration ?: $p->shots->sum('duration')),
                'status' => $p->status,
                'error' => $p->error,
                'shots_done' => $p->shots->where('status', 'completed')->count(),
                'shots_total' => $p->shots->count(),
                'quoted' => (float) $p->estimated_cost,
                'billed' => (float) $p->billed_total,
                'raw_cost' => (float) $p->shots->sum('raw_cost'),
                'film_url' => $p->output_path ? $signed('film') : null,
                'poster_url' => $p->thumbnail_path ? $signed('poster') : null,
                'created_at' => $p->created_at,
            ];
        }));
    }
}
