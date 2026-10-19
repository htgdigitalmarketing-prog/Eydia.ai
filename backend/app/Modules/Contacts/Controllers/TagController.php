<?php

namespace App\Modules\Contacts\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('contacts.view');

        $tags = Tag::withCount('contacts')->orderBy('name')->get();

        return $this->ok([
            'tags' => $tags->map(fn ($t) => [
                'id' => $t->uuid,
                'name' => $t->name,
                'color' => $t->color,
                'contacts_count' => $t->contacts_count,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('contacts.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $tag = Tag::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#' . substr(md5($data['name']), 0, 6),
        ]);

        return $this->ok(['tag' => [
            'id' => $tag->uuid,
            'name' => $tag->name,
            'color' => $tag->color,
            'contacts_count' => 0,
        ]], 201);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('contacts.delete');

        $tag = Tag::where('uuid', $uuid)->firstOrFail();
        $tag->contacts()->detach();
        $tag->delete();

        return $this->ok(['message' => 'Tag deleted.']);
    }
}
