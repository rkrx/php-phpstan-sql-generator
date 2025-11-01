<?php

namespace Kir\PhpstanTypesFromSql;


interface PhpstanTypeGeneratorInterface {
	/**
	 * @param callable(string): bool $tableFilter
	 * @return void
	 */
	public function addFilter(callable $tableFilter): void;
	
	/**
	 * @param string $namespace The namespace for the generated php-file to use.
	 * @param string $className The name of the class to generate.
	 * @param string|null $databaseName The database to generate types for.
	 * @param string|null $schemaName The schema to generate types for.
	 * @param bool $asArray If true, the type is an array, if false, an object.
	 * @param bool $full All keys are required to be present.
	 * @return string The generated php-file contents.
	 */
	public function generate(
		string $namespace,
		string $className,
		?string $databaseName = null,
		?string $schemaName = null,
		bool $asArray = true,
		bool $full = true
	): string;
}