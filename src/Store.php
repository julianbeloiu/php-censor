<?php

declare(strict_types=1);

namespace PHPCensor;

use Exception;
use PDO;
use PHPCensor\Common\Exception\InvalidArgumentException;
use PHPCensor\Common\Exception\RuntimeException;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
abstract class Store
{
    protected ?string $modelName = null;

    protected string $tableName = '';

    protected ?string $primaryKey = null;

    protected DatabaseManager $databaseManager;

    protected StoreRegistry $storeRegistry;

    abstract public function getByPrimaryKey(int $key, string $useConnection = 'read'): ?Model;

    /**
     * @throws RuntimeException
     */
    public function __construct(
        DatabaseManager $databaseManager,
        StoreRegistry $storeRegistry
    ) {
        if (empty($this->primaryKey)) {
            throw new RuntimeException('Save not implemented for this store.');
        }

        $this->databaseManager = $databaseManager;
        $this->storeRegistry = $storeRegistry;
    }

    /**
     * @throws Common\Exception\Exception
     * @throws InvalidArgumentException
     */
    public function getWhere(
        array $where = [],
        int $limit = 25,
        int $offset = 0,
        array $order = [],
        string $whereType = 'AND'
    ): array {
        $query = 'SELECT * FROM {{' . $this->tableName . '}}';
        $countQuery = 'SELECT COUNT(*) AS {{count}} FROM {{' . $this->tableName . '}}';

        $wheres = [];
        $params = [];
        foreach ($where as $key => $value) {
            $key = $this->fieldCheck($key);

            if (!\is_array($value)) {
                $params[] = $value;
                $wheres[] = $key . ' = ?';
            }
        }

        if (\count($wheres)) {
            $query .= ' WHERE (' . \implode(' ' . $whereType . ' ', $wheres) . ')';
            $countQuery .= ' WHERE (' . \implode(' ' . $whereType . ' ', $wheres) . ')';
        }

        if (\count($order)) {
            $orders = [];
            foreach ($order as $key => $value) {
                $orders[] = $this->fieldCheck($key) . ' ' . $value;
            }

            $query .= ' ORDER BY ' . \implode(', ', $orders);
        }

        if ($limit) {
            $query .= ' LIMIT ' . $limit;
        }

        if ($offset) {
            $query .= ' OFFSET ' . $offset;
        }

        $stmt = $this->databaseManager->getConnection('read')->prepare($countQuery);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)$res['count'];

        $stmt = $this->databaseManager->getConnection('read')->prepare($query);
        $stmt->execute($params);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rtn = [];

        foreach ($res as $data) {
            $rtn[] = new $this->modelName($this->storeRegistry, $data);
        }

        return ['items' => $rtn, 'count' => $count];
    }

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function save(Model $model): ?Model
    {
        if (!($model instanceof $this->modelName)) {
            throw new InvalidArgumentException(get_class($model) . ' is an invalid model type for this store.');
        }

        $data = $this->getData($model);

        if ($model->getId() !== null) {
            return $this->saveByUpdate($model, $data);
        }

        return $this->saveByInsert($model, $data);
    }

    /**
     * @throws Exception
     */
    protected function saveByUpdate(Model $model, array $data): ?Model
    {
        if (empty($data)) {
            return $model;
        }

        $updates = [];
        $updateParams = [];
        foreach ($data as $column => $value) {
            $updates[] = $column . ' = :' . $column;
            $updateParams[] = [$column, $value];
        }

        $queryString = sprintf(
            'UPDATE {{%s}} SET %s WHERE {{%s}} = :primaryKey',
            $this->tableName,
            implode(', ', $updates),
            $this->primaryKey
        );
        $query = $this->databaseManager
            ->getConnection('write')
            ->prepare($queryString);

        foreach ($updateParams as $updateParam) {
            $query->bindValue(':' . $updateParam[0], $updateParam[1]);
        }

        $query->bindValue(':primaryKey', $data[$this->primaryKey]);
        $query->execute();

        return $this->getByPrimaryKey($model->getId(), 'write');
    }

    /**
     * @throws Exception
     */
    protected function saveByInsert(Model $model, array $data): ?Model
    {
        if (empty($data)) {
            return $model;
        }

        $cols = [];
        $values = [];
        $queryParams = [];
        foreach ($data as $column => $value) {
            $cols[] = $column;
            $values[] = ':' . $column;
            $queryParams[':' . $column] = $value;
        }

        $queryString = sprintf(
            'INSERT INTO {{%s}} (%s) VALUES (%s)',
            $this->tableName,
            implode(', ', $cols),
            implode(', ', $values)
        );
        $query = $this->databaseManager
            ->getConnection('write')
            ->prepare($queryString);

        if (!$query->execute($queryParams)) {
            return $model;
        }

        $id = $this->databaseManager
            ->getConnection('write')
            ->lastInsertId($this->tableName);

        return $this->getByPrimaryKey($id, 'write');
    }

    /**
     * @throws Common\Exception\Exception
     * @throws InvalidArgumentException
     */
    public function delete(Model $model): bool
    {
        if (!($model instanceof $this->modelName)) {
            throw new InvalidArgumentException(get_class($model) . ' is an invalid model type for this store.');
        }

        $query = $this->databaseManager->getConnection('write')
            ->prepare(
                sprintf(
                    'DELETE FROM {{%s}} WHERE {{%s}} = :primaryKey',
                    $this->tableName,
                    $this->primaryKey
                )
            );
        $query->bindValue(':primaryKey', $model->getId());
        $query->execute();

        return true;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function fieldCheck(string $field): string
    {
        if (empty($field)) {
            throw new InvalidArgumentException('You cannot have an empty field name.');
        }

        if (\strpos($field, '.') === false) {
            return '{{' . $this->tableName . '}}.{{' . $field . '}}';
        }

        return $field;
    }

    protected function getData(Model $model): array
    {
        $rawData = $model->getDataArray();
        $modified = $model->getModified();
        $data = [];
        foreach ($rawData as $column => $value) {
            if (!array_key_exists($column, $modified)) {
                continue;
            }
            $data[$column] = $this->castToDatabase($model->getCast($column), $value);
        }

        return $data;
    }

    /**
     * @return mixed
     */
    private function castToDatabase(string $type, $value)
    {
        if ($value === null || gettype($value) === 'string') {
            return $value;
        }

        switch ($type) {
            case 'datetime':
                return $value->format('Y-m-d H:i:s');
            case 'array':
                return json_encode($value);
            case 'newline':
                return implode("\n", $value);
            default:
                return $value;
        }
    }
}
