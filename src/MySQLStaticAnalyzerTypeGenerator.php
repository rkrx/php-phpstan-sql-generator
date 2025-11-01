<?php

namespace Kir\PhpstanTypesFromSql;

use Generator;
use Kir\PhpstanTypesFromSql\Common\LangTools;
use Kir\PhpstanTypesFromSql\Common\PHPTools;
use Override;
use PDO;
use RuntimeException;

/**
 * @phpstan-type TMySQLColumn object{
 *   TABLE_CATALOG: string,
 *   TABLE_SCHEMA: string,
 *   TABLE_NAME: string,
 *   COLUMN_NAME: string,
 *   ORDINAL_POSITION: positive-int,
 *   COLUMN_DEFAULT: string|null,
 *   IS_NULLABLE: string,
 *   DATA_TYPE: string,
 *   CHARACTER_MAXIMUM_LENGTH: positive-int|null,
 *   CHARACTER_OCTET_LENGTH: positive-int|null,
 *   NUMERIC_PRECISION: positive-int|null,
 *   NUMERIC_SCALE: positive-int|null,
 *   DATETIME_PRECISION: positive-int|null,
 *   CHARACTER_SET_NAME: string|null,
 *   COLLATION_NAME: string|null,
 *   COLUMN_TYPE: string,
 *   COLUMN_KEY: string,
 *   EXTRA: string,
 *   PRIVILEGES: string,
 *   COLUMN_COMMENT: string,
 *   IS_GENERATED: string,
 *   GENERATION_EXPRESSION: string|null
 * }
 */
class MySQLStaticAnalyzerTypeGenerator implements PhpstanTypeGeneratorInterface {
	/** @var list<callable(string): bool> */
	private array $tableFilters = [];

	public function __construct(
		private readonly PDO $pdo,
	) {}

	/**
	 * @param callable(string): bool $tableFilter
	 * @return void
	 */
	#[Override]
	public function addFilter(callable $tableFilter): void {
		$this->tableFilters[] = $tableFilter;
	}

	#[Override]
	public function generate(
		string $namespace,
		string $className,
		?string $databaseName = null,
		?string $schemaName = null,
		bool $asArray = true,
		bool $full = true,
		bool|callable $singularizedNames = true
	): string {
		$typeLinesStr = $this->generatePhpStanTableDefinitions(databaseName: $databaseName, asArray: $asArray, full: $full, singularizedNames: $singularizedNames);

		$docBlock = PHPTools::embedLinesIntoPhpDockBlock($typeLinesStr);

		$content = ['<?php', ''];
		$content[] = "namespace {$namespace};";
		$content[] = '';
		$content[] = $docBlock;
		$content[] = "class {$className} {\n}\n";

		return implode("\n", $content);
	}
	
	/**
	 * @param null|string $databaseName The database to generate types for.
	 * @param bool $asArray If true, the type is an array, if false, an object.
	 * @param bool $full All keys are required to be present.
	 * @param null|bool|callable(string):string $singularizedNames If true, table names are singularized.
	 * @return string
	 * @throws \JsonException
	 */
	public function generatePhpStanTableDefinitions(
		?string $databaseName,
		bool $asArray,
		bool $full,
		null|bool|callable $singularizedNames
	): string {
		$tables = $this->getTablesAndColumns(databaseName: $databaseName);

		$typeLines = [];

		foreach($tables as $tableName => $columns) {
			$typeName = strtr($tableName, ['_' => ' ']);
			$typeName = ucwords($typeName);
			$typeName = strtr($typeName, [' ' => '']);

			$typeLines = [
				...$typeLines,
				...$this->generateTableDefinition(
					analyzerPrefix: 'phpstan',
					typeName: $typeName,
					columns: $columns,
					asArray: $asArray,
					full: $full,
					singularizedNames: $singularizedNames,
				)
			];

			$typeLines = [
				...$typeLines,
				...$this->generateTableDefinition(
					analyzerPrefix: 'psalm',
					typeName: $typeName,
					columns: $columns,
					asArray: $asArray,
					full: $full,
					singularizedNames: $singularizedNames,
				)
			];
		}

		return implode("\n", $typeLines);
	}

	/**
	 * @param null|string $databaseName
	 * @return array<string, TMySQLColumn[]>
	 */
	private function getTablesAndColumns(?string $databaseName): array {
		$columns = $this->getAllColumns(databaseName: $databaseName);
		$result = [];
		foreach($columns as $column) {
			$result[$column->TABLE_NAME] ??= [];
			$result[$column->TABLE_NAME][] = $column;
		}
		return $result;
	}

	/**
	 * @param null|string $databaseName
	 * @return Generator<TMySQLColumn>
	 */
	private function getAllColumns(?string $databaseName): Generator {
		$conditions = ['1=1'];
		$parameters = [];

		if($databaseName !== null) {
			$conditions[] = 'c.TABLE_SCHEMA = :dbname';
			$parameters['dbname'] = $databaseName;
		} else {
			$conditions[] = 'c.TABLE_SCHEMA = DATABASE()';
		}

		$sql = sprintf('SELECT * FROM information_schema.COLUMNS c WHERE %s', implode(' AND ', $conditions));
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($parameters);

		/** @var TMySQLColumn[] $result */
		$result = $stmt->fetchAll(PDO::FETCH_CLASS);

		if(count($this->tableFilters)) {
			foreach($result as $column) {
				foreach($this->tableFilters as $filter) {
					if($filter($column->TABLE_NAME)) {
						yield $column;
					}
				}
			}
		} else {
			yield from $result;
		}
	}

	/**
	 * @param string $analyzerPrefix The prefix to use for the analyzer (e.g. phpstan, psalm).
	 * @param string $typeName
	 * @param TMySQLColumn[] $columns
	 * @param bool $asArray If true, the type is an array, if false, an object.
	 * @param bool $full All keys are required to be present.
	 * @param null|bool|callable(string):string $singularizedNames If true, table names are singularized.
	 * @return string[]
	 * @throws \JsonException
	 */
	private function generateTableDefinition(
		string $analyzerPrefix,
		string $typeName,
		array $columns,
		bool $asArray,
		bool $full,
		null|bool|callable $singularizedNames
	): array {
		if($singularizedNames === true) {
			$typeName = LangTools::singularize($typeName);
		} elseif(is_callable($singularizedNames)) {
			$typeName = $singularizedNames($typeName);
		}
		
		$ro = $full ? '' : '_Partial';
		$type = $asArray ? 'array' : 'object';
		$typeLines = ["@$analyzerPrefix-type T{$typeName}$ro $type{"];

		$columnLines = [];

		foreach($columns as $column) {
			$fieldName = self::sanitizeField($column->COLUMN_NAME);

			$columnType = match(strtolower($column->DATA_TYPE)) {
				'boolean', 'bit' => 'bool',
				'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'year' => 'int',
				'decimal', 'float', 'double', 'real', 'numeric' => 'float',
				'char', 'varchar', 'binary', 'varbinary',
				'text', 'tinytext', 'mediumtext', 'longtext',
				'blob', 'tinyblob', 'mediumblob', 'longblob',
				'date', 'datetime', 'timestamp', 'time' => 'string',
				'set', 'enum' => self::parseEnumDef($column->COLUMN_TYPE),
				default => throw new RuntimeException("Unknown type: {$column->DATA_TYPE}"),
			};

			if($column->IS_NULLABLE === 'YES') {
				$columnType .= '|null';
			}

			$columnLines[] = sprintf("    %s: %s", $fieldName, $columnType);

		}

		$typeLines[] = implode(",\n", $columnLines);
		$typeLines[] = "}\n";

		return $typeLines;
	}

	private static function parseEnumDef(string $columnType): string {
		if(preg_match('{^(?:enum|set)\((.*)\)$}', $columnType, $matches)) {
			$options = str_getcsv($matches[1], ',', "'", "\\");
			$options = array_map(static fn ($option) => json_encode($option, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $options);
			return implode('|', $options);
		}
		throw new RuntimeException("Can't parse: {$columnType}");
	}

	private static function sanitizeField(string $field): string {
		if(!preg_match('{^\\w+$}', $field)) {
			return "'{$field}'";
		}
		return $field;
	}
}
