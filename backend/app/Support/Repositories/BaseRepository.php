<?php

namespace App\Support\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Thin base repository. Used only where a repository earns its place
 * (complex queries, reuse) — trivial CRUD stays in services per your rules.
 */
abstract class BaseRepository
{
    protected Model $model;

    public function find(string $id): ?Model
    {
        return $this->model->find($id);
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->model->latest()->paginate($perPage);
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }
}
