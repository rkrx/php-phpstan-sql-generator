<?php

namespace Kir\PhpstanTypesFromSql;

use Kir\PhpstanTypesFromSql\Common\LangTools;
use Kir\PhpstanTypesFromSql\Common\PHPTools;
use Kir\PhpstanTypesFromSql\Postgres\PostgresTypeTranslationService;
use Override;
use PDO;

use Generator;
use RuntimeException;

/**
 * @phpstan-type TPostgresColumn object{
 *   table_catalog: string,
 *   table_schema: string,
 *   table_name: string,
 *   column_name: string,
 *   ordinal_position: int,
 *   column_default: string|null,
 *   is_nullable: string,
 *   data_type: string,
 *   character_maximum_length: int|null,
 *   character_octet_length: int|null,
 *   numeric_precision: int|null,
 *   numeric_precision_radix: int|null,
 *   numeric_scale: int|null,
 *   datetime_precision: int|null,
 *   interval_type: string|null,
 *   interval_precision: int|null,
 *   character_set_catalog: string|null,
 *   character_set_schema: string|null,
 *   character_set_name: string|null,
 *   collation_catalog: string|null,
 *   collation_schema: string|null,
 *   collation_name: string|null,
 *   domain_catalog: string|null,
 *   domain_schema: string|null,
 *   domain_name: string|null,
 *   udt_catalog: string|null,
 *   udt_schema: string|null,
 *   udt_name: string|null,
 *   scope_catalog: string|null,
 *   scope_schema: string|null,
 *   scope_name: string|null,
 *   maximum_cardinality: int|null,
 *   dtd_identifier: string|null,
 *   is_self_referencing: string,
 *   is_identity: string,
 *   identity_generation: string|null,
 *   identity_start: string|null,
 *   identity_increment: string|null,
 *   identity_maximum: string|null,
 *   identity_minimum: string|null,
 *   identity_cycle: string,
 *   is_generated: string,
 *   generation_expression: string|null,
 *   is_updatable: string
 * }
 */
class PostgresStaticAnalyzerTypeGenerator implements PhpstanTypeGeneratorInterface {
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
		$tables = $this->getAllTables(databaseName: $databaseName, schemaName: $schemaName);
		
		$blocks = [];
		foreach($tables as $tableName => $columns) {
			$properties = [];
			foreach($columns as $column) {
				$properties[$column->column_name] = PostgresTypeTranslationService::asPhpType($column->data_type, $column->is_nullable === 'YES');
			}
			
			if($singularizedNames === true) {
				$tableName = LangTools::singularize($tableName);
			} elseif(is_callable($singularizedNames)) {
				$tableName = $singularizedNames($tableName);
			}
			
			$block = PHPTools::generateAnalyzerShape(
				analyzerPrefix: 'phpstan',
				tableName: $tableName,
				shapeType: $asArray ? 'array' : 'object',
				properties: $properties
			);
			
			$blocks[] = $block;
			
			$block = PHPTools::generateAnalyzerShape(
				analyzerPrefix: 'psalm',
				tableName: $tableName,
				shapeType: 'array',
				properties: $properties
			);
			
			$blocks[] = $block;
		}
		
		$docBlock = PHPTools::embedLinesIntoPhpDockBlock(implode("\n", $blocks));
		
		$content = ['<?php', ''];
		$content[] = "namespace {$namespace};";
		$content[] = '';
		$content[] = $docBlock;
		$content[] = "class {$className} {\n}\n";
		
		return implode("\n", $content);
	}
	
	/**
	 * @param string|null $databaseName
	 * @param string|null $schemaName
	 * @return array<string, TPostgresColumn[]>
	 */
	private function getAllTables(?string $databaseName, ?string $schemaName): array {
		$columns = $this->getAllColumns(databaseName: $databaseName, schemaName: $schemaName);
		$result = [];
		foreach($columns as $column) {
			$result[$column->table_name] ??= [];
			$result[$column->table_name][] = $column;
		}
		return $result;
	}
	
	/**
	 * @param string|null $databaseName
	 * @param string|null $schemaName
	 * @return Generator<TPostgresColumn>
	 */
	private function getAllColumns(?string $databaseName, ?string $schemaName): Generator {
		$conditions = [];
		$parameters = [];

		if($databaseName !== null) {
			$conditions[] = 'table_catalog = :dbname';
			$parameters['dbname'] = $databaseName;
		} else {
			$conditions[] = 'table_catalog = current_database()';
		}

		if($schemaName !== null) {
			$conditions[] = 'table_schema = :schema';
			$parameters['schema'] = $schemaName;
		} else {
			$conditions[] = 'table_schema = current_schema()';
		}
		
		$stmt = $this->pdo->prepare('SELECT * FROM information_schema.columns WHERE ' . implode(' AND ', $conditions));
		$stmt->execute($parameters);
		
		/** @var TPostgresColumn[] $result */
		$result = $stmt->fetchAll(PDO::FETCH_CLASS);
		
		if(count($this->tableFilters)) {
			foreach($result as $column) {
				foreach($this->tableFilters as $filter) {
					if($filter($column->table_name)) {
						yield $column;
					}
				}
			}
		} else {
			yield from $result;
		}
	}
}