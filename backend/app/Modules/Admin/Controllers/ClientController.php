<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super-admin CRUD for the public "Our Clients" list. */
class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'clients' => Client::orderBy('sort_order')->orderBy('id')->get()->map(fn (Client $c) => $this->arr($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validated($request));

        return $this->ok(['client' => $this->arr($client)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $client = Client::where('uuid', $uuid)->firstOrFail();
        $client->update($this->validated($request));

        return $this->ok(['client' => $this->arr($client->fresh())]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        Client::where('uuid', $uuid)->firstOrFail()->delete();

        return $this->ok(['message' => 'Client removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'logo_url' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function arr(Client $c): array
    {
        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'logo_url' => $c->logo_url,
            'website' => $c->website,
            'sort_order' => $c->sort_order,
            'is_active' => $c->is_active,
        ];
    }
}
