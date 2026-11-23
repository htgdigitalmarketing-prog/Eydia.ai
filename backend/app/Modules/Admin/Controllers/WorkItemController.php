<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super-admin CRUD for the public "Our Work" portfolio. */
class WorkItemController extends Controller
{
    /** Suggested categories (the admin may also type a custom one). */
    public const CATEGORIES = [
        'Websites', 'Web Application', 'Android App', 'Google Ads',
        'Meta Ads', 'Google Business Profile', 'SEO', 'Branding',
    ];

    public function index(): JsonResponse
    {
        return $this->ok([
            'items' => WorkItem::orderBy('category')->orderBy('sort_order')->orderBy('id')
                ->get()->map(fn (WorkItem $w) => $this->arr($w)),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = WorkItem::create($this->validated($request));

        return $this->ok(['item' => $this->arr($item)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $item = WorkItem::where('uuid', $uuid)->firstOrFail();
        $item->update($this->validated($request));

        return $this->ok(['item' => $this->arr($item->fresh())]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        WorkItem::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Work item removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:80'],
            'image_url' => ['nullable', 'string', 'max:1000'],
            'link' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function arr(WorkItem $w): array
    {
        return [
            'id' => $w->uuid,
            'title' => $w->title,
            'category' => $w->category,
            'image_url' => $w->image_url,
            'link' => $w->link,
            'description' => $w->description,
            'sort_order' => $w->sort_order,
            'is_active' => $w->is_active,
        ];
    }
}
