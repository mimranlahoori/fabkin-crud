<?php

namespace Lahori\FabkinCrud\Commands;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;

class CrudGenerator extends GeneratorCommand
{
    protected $signature = 'make:crud {name : Table name} {--route= : Custom route name}';

    protected $description = 'Create Laravel CRUD operations with Bootstrap';

    public function handle()
    {
        $this->info('Running Crud Generator (Bootstrap only)...');

        $this->table = $this->getNameInput();

        if (! $this->tableExists()) {
            $this->error("`$this->table` table not exist");
            return false;
        }

        $this->name = $this->_buildClassName();

        $this->buildController()
            ->buildModel()
            ->buildViews()
            ->writeRoute();

        $this->info('Created Successfully.');

        return true;
    }

    protected function writeRoute(): static
    {
        $replacements = $this->buildReplacements();

        $this->info('Please add this route in web.php:');
        $this->info('');

        $line = "Route::resource('" . $this->_getRoute() . "', {$this->name}Controller::class);";

        $this->info('<bg=blue;fg=white>'.$line.'</>');
        $this->info('');

        return $this;
    }

    protected function buildController(): static
    {
        $controllerPath = $this->_getControllerPath($this->name);

        if ($this->files->exists($controllerPath) && $this->ask('Already exist Controller. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Controller ...');

        $replace = $this->buildReplacements();

        $controllerTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('Controller') // sirf bootstrap wali stub rakho
        );

        $this->write($controllerPath, $controllerTemplate);

        return $this;
    }

    protected function buildModel(): static
    {
        $modelPath = $this->_getModelPath($this->name);

        if ($this->files->exists($modelPath) && $this->ask('Already exist Model. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Model ...');

        $replace = array_merge($this->buildReplacements(), $this->modelReplacements());

        $modelTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('Model')
        );

        $this->write($modelPath, $modelTemplate);

        $requestPath = $this->_getRequestPath($this->name);

        $this->info('Creating Request Class ...');

        $requestTemplate = str_replace(
            array_keys($replace),
            array_values($replace),
            $this->getStub('Request')
        );

        $this->write($requestPath, $requestTemplate);

        return $this;
    }

    protected function buildViews(): static
    {
        $this->info('Creating Views (Bootstrap) ...');

        $tableHead = "\n";
        $tableBody = "\n";
        $viewRows = "\n";
        $form = "\n";

        foreach ($this->getFilteredColumns() as $column) {
            $title = Str::title(str_replace('_', ' ', $column));

            $tableHead .= $this->getHead($title);
            $tableBody .= $this->getBody($column);
            $viewRows .= $this->getField($title, $column, 'view-field');
            $form .= $this->getField($title, $column);
        }

        $replace = array_merge($this->buildReplacements(), [
            '{{tableHeader}}' => $tableHead,
            '{{tableBody}}' => $tableBody,
            '{{viewRows}}' => $viewRows,
            '{{form}}' => $form,
        ]);

        $this->buildLayout();

        foreach (['index', 'create', 'edit', 'form', 'show'] as $view) {
            $path = "views/bootstrap/$view";

            $viewTemplate = str_replace(
                array_keys($replace),
                array_values($replace),
                $this->getStub($path)
            );

            $this->write($this->_getViewPath($view), $viewTemplate);
        }

        return $this;
    }

    private function _buildClassName(): string
    {
        return Str::studly(Str::singular($this->table));
    }
}
