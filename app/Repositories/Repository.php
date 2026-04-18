<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Exception;

abstract class Repository
{
    /**
     * Model Class 名稱
     * 
     * @var string
     */
    protected string $modelClassName;

    /**
     * 建構子
     * 
     * @return void
     * 
     * @throws \Exception
     */
    public function __construct()
    {
        $classReflector = new ReflectionClass($this);
        $repositoryClassShortName = $classReflector->getShortName();
        $modelClassShortName = strstr($repositoryClassShortName, 'Repository', true);

        if (!class_exists($modelClassFullName = "App\\Models\\$modelClassShortName")) {
            throw new Exception("Model class [$modelClassFullName] does not exist.");
        }

        $classReflector = new ReflectionClass($modelClassFullName);

        if (!$classReflector->isSubclassOf(Model::class)) {
            throw new Exception("Class [$modelClassFullName] is not a valid Eloquent model.");
        }

        $this->modelClassName = $modelClassFullName;
    }

    /**
     * 新增資料
     * 
     * @param array<string, mixed> $data
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array $data)
    {
        return $this->modelClassName::create($data);
    }

    /**
     * 更新資料
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @param array<string, mixed> $data
     * @return int
     */
    public function update(array $conditions, array $data)
    {
        return $this->filter($conditions)->update($data);
    }

    /**
     * 刪除資料
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return int
     */
    public function delete(array $conditions)
    {
        return $this->filter($conditions)->delete();
    }

    /**
     * 取得條件篩選後的資料
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @param array<int, string> $relations
     * @param array<int, string> $orderBy
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get(array $conditions, array $relations = [], array $orderBy = ['id', 'asc'], int $limit = 100)
    {
        return $this->filter($conditions)
            ->with($relations)
            ->orderBy(...$orderBy)
            ->limit($limit)
            ->get();
    }

    /**
     * 取得經條件篩選後，指定頁碼內的資料
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @param array<int, string> $relations
     * @param array<int, string> $orderBy
     * @param int $rowCountsPerPage
     * @param int $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(array $conditions, array $relations = [], array $orderBy = ['id', 'asc'], int $rowCountsPerPage = 10, int $page = 1)
    {
        return $this->filter($conditions)
            ->with($relations)
            ->orderBy(...$orderBy)
            ->paginate($rowCountsPerPage, ['*'], 'page', $page);
    }

    /**
     * 取得條件篩選後的第一筆資料
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function first(array $conditions)
    {
        return $this->filter($conditions)->first();
    }

    /**
     * 取得條件篩選後的資料筆數
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return int
     */
    public function count(array $conditions)
    {
        return $this->filter($conditions)->count();
    }

    /**
     * 取得條件篩選後的資料是否存在
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return bool
     */
    public function exists(array $conditions)
    {
        return $this->filter($conditions)->exists();
    }

    /**
     * 取得條件篩選後的資料是否不存在
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return bool
     */
    public function doesNotExist(array $conditions)
    {
        return $this->filter($conditions)->doesntExist();
    }

    /**
     * 取得所有資料
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        return $this->modelClassName::all();
    }

    /**
     * 資料條件篩選
     * 
     * @param array<int, array<int, mixed>> $conditions
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function filter(array $conditions)
    {
        $query = $this->modelClassName::query();

        foreach ($conditions as $condition) {
            $query = $query->where(...$condition);
        }

        return $query;
    }
}
