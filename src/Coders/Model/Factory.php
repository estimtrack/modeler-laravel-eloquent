<?php

namespace Pursehouse\Modeler\Coders\Model;

use Illuminate\Support\Str;
use Pursehouse\Modeler\Meta\Blueprint;
use Pursehouse\Modeler\Support\Classify;
use Pursehouse\Modeler\Meta\SchemaManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;

class Factory
{
    /**
     * @var \Illuminate\Database\DatabaseManager
     */
    private $db;

    /**
     * @var \Pursehouse\Modeler\Meta\SchemaManager
     */
    protected $schemas = [];

    /**
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * @var \Pursehouse\Modeler\Support\Classify
     */
    protected $class;

    /**
     * @var \Pursehouse\Modeler\Coders\Model\Config
     */
    protected $config;

    /**
     * @var \Pursehouse\Modeler\Coders\Model\ModelManager
     */
    protected $models;

    /**
     * @var \Pursehouse\Modeler\Coders\Model\Mutator[]
     */
    protected $mutators = [];

    /**
     * @var string
     */
    protected $schema_namespace;

    /**
     * ModelsFactory constructor.
     *
     * @param \Illuminate\Database\DatabaseManager $db
     * @param \Illuminate\Filesystem\Filesystem    $files
     * @param \Reliese\Support\Classify            $writer
     * @param \Pursehouse\Modeler\Coders\Model\Config         $config
     */
    public function __construct(DatabaseManager $db, Filesystem $files, Classify $writer, Config $config)
    {
        $this->db = $db;
        $this->files = $files;
        $this->config = $config;
        $this->class = $writer;
    }

    /**
     * @return \Pursehouse\Modeler\Coders\Model\Mutator
     */
    public function mutate()
    {
        return $this->mutators[] = new Mutator();
    }

    /**
     * @return \Pursehouse\Modeler\Coders\Model\ModelManager
     */
    protected function models()
    {
        if (! isset($this->models)) {
            $this->models = new ModelManager($this);
        }

        return $this->models;
    }

    /**
     * Select connection to work with.
     *
     * @param string $connection
     *
     * @return $this
     */
    public function on($connection = null)
    {
        $this->schemas = new SchemaManager($this->db->connection($connection));

        return $this;
    }

    /**
     * Map all schemas found in the database.
     */
    public function mapAll()
    {
        if (! isset($this->schemas)) {
            $this->on();
        }

        foreach ($this->schemas as $schema) {
            $this->map($schema->schema());
        }
    }

    /**
     * @param string $schemaName
     */
    public function map($schemaName)
    {
        if (! isset($this->schemas)) {
            $this->on();
        }

        $mapper = $this->makeSchema($schemaName);

        foreach ($mapper->tables() as $blueprint) {
            if ($this->shouldTakeOnly($blueprint) && $this->shouldNotExclude($blueprint)) {
                $this->create($mapper->schema(), $blueprint->table());
            }
        }
    }

    /**
     * @param \Pursehouse\Modeler\Meta\Blueprint $blueprint
     *
     * @return bool
     */
    protected function shouldNotExclude(Blueprint $blueprint)
    {
        foreach ($this->config($blueprint, 'except', []) as $pattern) {
            if (Str::is($pattern, $blueprint->table())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \Pursehouse\Modeler\Meta\Blueprint $blueprint
     *
     * @return bool
     */
    protected function shouldTakeOnly(Blueprint $blueprint)
    {
        if ($patterns = $this->config($blueprint, 'only', [])) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $blueprint->table())) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param string $schema
     * @param string $table
     */
    public function create($schema, $table)
    {

        $model = $this->makeModel($schema, $table);

        $template = $this->prepareTemplate($model, 'model');

        $file = $this->fillTemplate($template, $model);

        if ($model->indentWithSpace()) {
            $file = str_replace("\t", str_repeat(' ', $model->indentWithSpace()), $file);
        }

        $this->files->put($this->modelPath($model, $model->usesBaseFiles() ? ['Base'] : []), $file);

        if ($this->needsUserFile($model)) {
            $this->createUserFile($model);
        }
    }

    /**
     * @param string $schema
     * @param string $table
     * @param bool   $withRelations
     *
     * @return \Pursehouse\Modeler\Coders\Model\Model
     */
    public function makeModel($schema, $table, $withRelations = true)
    {
        return $this->models()->make($schema, $table, $this->mutators, $withRelations);
    }

    /**
     * @param string $schema
     *
     * @return \Pursehouse\Modeler\Meta\Schema
     */
    public function makeSchema($schema)
    {
        return $this->schemas->make($schema);
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @todo: Delegate workload to SchemaManager and ModelManager
     *
     * @return array
     */
    public function referencing(Model $model)
    {
        $references = [];

        // TODO: SchemaManager should do this
        foreach ($this->schemas as $schema) {
            $references = array_merge($references, $schema->referencing($model->getBlueprint()));
        }

        // TODO: ModelManager should do this
        foreach ($references as &$related) {
            $blueprint = $related['blueprint'];
            $related['model'] = $model->getBlueprint()->is($blueprint->schema(), $blueprint->table())
                ? $model
                : $this->makeModel($blueprint->schema(), $blueprint->table(), false);
        }

        return $references;
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     * @param string                      $name
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     *
     * @return string
     */
    protected function prepareTemplate(Model $model, $name)
    {
        $defaultFile = $this->path([__DIR__, 'Templates', $name]);
        $file = $this->config($model->getBlueprint(), "*.template.$name", $defaultFile);

        return $this->files->get($file);
    }

    /**
     * @param string                      $template
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @return mixed
     */
    protected function fillTemplate($template, Model $model)
    {
        $template = str_replace('{{namespace}}', $model->getBaseNamespace(), $template);
        $template = str_replace('{{class}}', $model->getClassName(), $template);

        $properties = $this->properties($model);
        $usedClasses = $this->extractUsedClasses($properties);
        $template = str_replace('{{properties}}', $properties, $template);

        $parentClass = $model->getParentClass();
        $usedClasses = array_merge($usedClasses, $this->extractUsedClasses($parentClass));
        $template = str_replace('{{parent}}', $parentClass, $template);

        $body = $this->body($model);
        $usedClasses = array_merge($usedClasses, $this->extractUsedClasses($body));
        $template = str_replace('{{body}}', $body, $template);

        $usedClasses = array_unique($usedClasses);
        $usedClassesSection = $this->formatUsedClasses(
            $model->getClassName(),
            $model->getBaseNamespace(),
            $usedClasses
        );
        $usedClassesSection = '';
        $template = str_replace('{{imports}}', $usedClassesSection, $template);

        return $template;
    }

    /**
     * Returns imports section for model.
     *
     * @param string $baseNamespace base namespace to avoid importing classes from same namespace
     * @param array  $usedClasses   Array of used in model classes
     *
     * @return string
     */
    private function formatUsedClasses($nameSpace, $baseNamespace, $usedClasses)
    {
        $result = [];
        foreach ($usedClasses as $usedClass) {

            // Do not import classes from same namespace
            $namespacePattern = str_replace('\\', '\\\\', "/{$baseNamespace}\\[a-zA-Z0-9_]*/");

            if (! preg_match($namespacePattern, $usedClass)) {
                $namespacePattern = str_replace('\\', '\\\\', "/{$baseNamespace}\\[a-zA-Z0-9_]*/");

                if (! preg_match($namespacePattern, $usedClass)) {
                    $result[] = "use {$usedClass};";
//                     $alias = $this->getUseClassAliasFromString( $usedClass );
//                     $result[] = "use {$usedClass} as {$alias};";
                }
            }
        }

        sort($result);

        return implode("\n", $result);
    }

    private function getUseClassAliasFromString($usedClass)
    {
        // need enhancements to this for handling if Databse or Schema aren't in the class namespace
        $expl = explode('\\', $usedClass);
        $tableName = array_pop($expl);
        $schemaName = array_pop($expl);
        $databaseName = array_pop($expl);

        return $this->formatUseClassAlias($tableName, $schemaName, $databaseName);
    }

    private function formatUseClassAlias($tableName, $schemaName, $databaseName)
    {
        return $databaseName.'_'.$schemaName.'_'.$tableName;
    }

    /**
     * Extract and replace fully-qualified class names from placeholder.
     *
     * @param string $placeholder Placeholder to extract class names from. Rewrites value to content without FQN
     *
     * @return array Extracted FQN
     */
    private function extractUsedClasses(&$placeholder)
    {
        $classNamespaceRegExp = '/([\\\\a-zA-Z0-9_]*\\\\[\\\\a-zA-Z0-9_]*)/';
        $matches = [];
        $usedInModelClasses = [];
        if (preg_match_all($classNamespaceRegExp, $placeholder, $matches)) {
            foreach ($matches[1] as $match) {
                $usedClassName = $match;
                $usedInModelClasses[] = trim($usedClassName, '\\');
                $namespaceParts = explode('\\', $usedClassName);
                $resultClassName = array_pop($namespaceParts);
//                 $placeholder = str_replace($usedClassName, $resultClassName, $placeholder);
            }
        }

        return array_unique($usedInModelClasses);
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @return string
     */
    protected function properties(Model $model)
    {
        // Process property annotations
        $annotations = '';

        foreach ($model->getProperties() as $name => $hint) {
            $annotations .= $this->class->annotation('property', "$hint \$$name");
        }

        if ($model->hasRelations()) {
            // Add separation between model properties and model relations
            $annotations .= "\n * ";
        }

        foreach ($model->getRelations() as $name => $relation) {
            // TODO: Handle collisions, perhaps rename the relation.
            if ($model->hasProperty($name)) {
                continue;
            }
            $annotations .= $this->class->annotation('property', $relation->hint()." \$$name");
        }

        return $annotations;
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @return string
     */
    protected function body(Model $model)
    {
        $body = '';

        foreach ($model->getTraits() as $trait) {
            $body .= $this->class->mixin($trait);
        }

        $excludedConstants = [];

        if ($model->hasCustomCreatedAtField()) {
            $body .= $this->class->constant('CREATED_AT', $model->getCreatedAtField());
            $excludedConstants[] = $model->getCreatedAtField();
        }

        if ($model->hasCustomUpdatedAtField()) {
            $body .= $this->class->constant('UPDATED_AT', $model->getUpdatedAtField());
            $excludedConstants[] = $model->getUpdatedAtField();
        }

        if ($model->hasCustomDeletedAtField()) {
            $body .= $this->class->constant('DELETED_AT', $model->getDeletedAtField());
            $excludedConstants[] = $model->getDeletedAtField();
        }

        if ($model->usesPropertyConstants()) {
            // Take all properties and exclude already added constants with timestamps.
            $properties = array_keys($model->getProperties());
            $properties = array_diff($properties, $excludedConstants);

            foreach ($properties as $property) {
                $body .= $this->class->constant(strtoupper($property), $property);
            }
        }

        $body = trim($body, "\n");
        // Separate constants from fields only if there are constants.
        if (! empty($body)) {
            $body .= "\n";
        }

        // Append connection name when required
        if ($model->shouldShowConnection()) {
            $body .= $this->class->field('connection', $model->getConnectionName());
        }

        // When table is not plural, append the table name
        if ($model->needsTableName()) {
            $body .= $this->class->field('table', $model->getTableForQuery());
        }

        if ($model->hasCustomPrimaryKey()) {
            $body .= $this->class->field('primaryKey', $model->getPrimaryKey());
        }

        if ($model->doesNotAutoincrement()) {
            $body .= $this->class->field('incrementing', false, ['visibility' => 'public']);
        }

        if ($model->hasCustomPerPage()) {
            $body .= $this->class->field('perPage', $model->getPerPage());
        }

        if (! $model->usesTimestamps()) {
            $body .= $this->class->field('timestamps', false, ['visibility' => 'public']);
        }

        if ($model->hasCustomDateFormat()) {
            $body .= $this->class->field('dateFormat', $model->getDateFormat());
        }

        if ($model->doesNotUseSnakeAttributes()) {
            $body .= $this->class->field('snakeAttributes', false, ['visibility' => 'public static']);
        }

        if ($model->hasCasts()) {
            $body .= $this->class->field('casts', $model->getCasts(), ['before' => "\n"]);
        }

        if ($model->hasDates()) {
            $body .= $this->class->field('dates', $model->getDates(), ['before' => "\n"]);
        }

        if ($model->hasHidden() && $model->doesNotUseBaseFiles()) {
            $body .= $this->class->field('hidden', $model->getHidden(), ['before' => "\n"]);
        }

        if ($model->hasFillable() && $model->doesNotUseBaseFiles()) {
            $body .= $this->class->field('fillable', $model->getFillable(), ['before' => "\n"]);
        }

        if ($model->hasHints() && $model->usesHints()) {
            $body .= $this->class->field('hints', $model->getHints(), ['before' => "\n"]);
        }

        foreach ($model->getMutations() as $mutation) {
            $body .= $this->class->method($mutation->name(), $mutation->body(), ['before' => "\n"]);
        }

        foreach ($model->getRelations() as $constraint) {
            $body .= $this->class->method($constraint->name(), $constraint->body(), ['before' => "\n"]);
        }

        // Make sure there not undesired line breaks
        $body = trim($body, "\n");

        return $body;
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     * @param array                       $custom
     *
     * @return string
     */
    protected function modelPath(Model $model, $custom = [])
    {
        $pathParts = [
            $this->config($model->getBlueprint(), 'path'),
        ];

        $doConnectionPath = $this->config($model->getBlueprint(), 'path_connection');
        $doSchemaNamespace = $this->config($model->getBlueprint(), 'namespace_schema');

        if ($doConnectionPath) {
            $pathParts[] = $this->transformSchemaToNamespace($model->getConnectionName());
        }

        if ($doSchemaNamespace) {
            $schema = $model->getSchema();
            $this->setSchemaNamespace($schema);
            $pathParts[] = $this->schema_namespace;
        }

        $pathParts = array_merge($pathParts, $custom);

        $modelsDirectory = $this->path($pathParts);

        if (! $this->files->isDirectory($modelsDirectory)) {
            $this->files->makeDirectory($modelsDirectory, 0755, true);
        }

        return $this->path([$modelsDirectory, $model->getClassName().'.php']);
    }

    /**
     * @param string $schema
     *
     * @return string
     */
    public function transformSchemaToNamespace($schema)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $schema)));
    }

    /**
     * @param string $schema
     *
     * @return void
     */
    protected function setSchemaNamespace($schema)
    {
        $this->schema_namespace = $this->transformSchemaToNamespace($schema);
    }

    /**
     * @param array $pieces
     *
     * @return string
     */
    protected function path($pieces)
    {
        return implode(DIRECTORY_SEPARATOR, (array) $pieces);
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @return bool
     */
    public function needsUserFile(Model $model)
    {
        return ! $this->files->exists($this->modelPath($model)) && $model->usesBaseFiles();
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function createUserFile(Model $model)
    {
        $file = $this->modelPath($model);

        $template = $this->prepareTemplate($model, 'user_model');
        $template = str_replace('{{namespace}}', $model->getNamespace(), $template);
        $template = str_replace('{{class}}', $model->getClassName(), $template);
        $template = str_replace('{{imports}}', $this->formatBaseClasses($model), $template);
        $template = str_replace('{{parent}}', $this->getBaseClassName($model), $template);
        $template = str_replace('{{body}}', $this->userFileBody($model), $template);

        $this->files->put($file, $template);
    }

    /**
     * @param Model $model
     *
     * @return string
     */
    private function formatBaseClasses(Model $model)
    {
        return "use {$model->getBaseNamespace()}\\{$model->getClassName()} as {$this->getBaseClassName($model)};";
    }

    /**
     * @param Model $model
     *
     * @return string
     */
    private function getBaseClassName(Model $model)
    {
        return 'Base'.$model->getClassName();
    }

    /**
     * @param \Pursehouse\Modeler\Coders\Model\Model $model
     *
     * @return string
     */
    protected function userFileBody(Model $model)
    {
        $body = '';

        if ($model->hasHidden()) {
            $body .= $this->class->field('hidden', $model->getHidden());
        }

        if ($model->hasFillable()) {
            $body .= $this->class->field('fillable', $model->getFillable(), ['before' => "\n"]);
        }

        // Make sure there is not an undesired line break at the end of the class body
        $body = ltrim(rtrim($body, "\n"), "\n");

        return $body;
    }

    /**
     * @param \Pursehouse\Modeler\Meta\Blueprint|null $blueprint
     * @param string                       $key
     * @param mixed                        $default
     *
     * @return mixed|\Pursehouse\Modeler\Coders\Model\Config
     */
    public function config(Blueprint $blueprint = null, $key = null, $default = null)
    {
        if (is_null($blueprint)) {
            return $this->config;
        }

        return $this->config->get($blueprint, $key, $default);
    }
}
