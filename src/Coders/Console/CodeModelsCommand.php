<?php

namespace Pursehouse\Modeler\Coders\Console;

use Illuminate\Console\Command;
use Pursehouse\Modeler\Coders\Model\Factory;
use Illuminate\Contracts\Config\Repository;

class CodeModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pursehouse:modeler
                            {--s|schema= : The name of the schema ( database in MySQL terms )}
                            {--c|connection= : The name of the connection}
                            {--t|table= : The name of the table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse connection schema into models';

    /**
     * @var \Pursehouse\Modeler\Coders\Model\Factory
     */
    protected $models;

    /**
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * Create a new command instance.
     *
     * @param \Pursehouse\Modeler\Coders\Model\Factory           $models
     * @param \Illuminate\Contracts\Config\Repository $config
     */
    public function __construct(Factory $models, Repository $config)
    {
        parent::__construct();

        $this->models = $models;
        $this->config = $config;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connection = $this->getConnection();
        $schema = $this->getSchema($connection);
        $table = $this->getTable();

        // Check whether we just need to generate one table
        if ($table) {
            $this->info("Making models for table : $table");
            $this->models->on($connection)->create($schema, $table);
            $this->info("Check out your models for $table");
        }

        // Otherwise map the schema
        elseif (! empty($schema)) {
            $this->info("Making models for schema : $schema");
            $this->models->on($connection)->map($schema);
            $this->info("Check out your models for $schema");
        }

        // Otherwise map the whole database
        else {
            $this->info('Making models for all schemas');
            $this->models->on($connection)->mapAll();
            $this->info('Check out your models for all schemas');
        }
    }

    /**
     * @return string
     */
    protected function getConnection()
    {
        return $this->option('connection') ?: $this->config->get('database.default');
    }

    /**
     * @param $connection
     *
     * @return string
     */
    protected function getSchema($connection)
    {
        return $this->option('schema');
    }

    /**
     * @return string
     */
    protected function getTable()
    {
        return $this->option('table');
    }
}
