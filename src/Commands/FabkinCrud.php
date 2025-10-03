<?php

namespace Lahori\FabkinCrud\Commands;

use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;

class FabkinCrud extends GeneratorCommand
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
    $viewRows  = "\n";
    $form      = "\n";

    foreach ($this->getFilteredColumns() as $column) {
        $title = Str::title(str_replace('_', ' ', $column));
        $type  = $this->_mapFieldType($column); // custom input type

        // Table Header
        $tableHead .= "<th>{$title}</th>\n";

        // Table Body
        $tableBody .= "<td>{{ \$${this->name}->${column} }}</td>\n";

        // Show View
        $viewRows .= "<p><strong>{$title}:</strong> {{ \$${this->name}->${column} }}</p>\n";

        // Form Fields
        if ($type === 'textarea') {
            $form .= "
<div class=\"mb-3\">
    <label for=\"{$column}\" class=\"form-label\">{$title}</label>
    <textarea name=\"{$column}\" id=\"{$column}\" class=\"form-control\">{{ old('$column', \$${this->name}->$column ?? '') }}</textarea>
    @error('$column') <span class=\"text-danger\">{{ \$message }}</span> @enderror
</div>\n";
        } elseif ($type === 'checkbox') {
            $form .= "
<div class=\"form-check mb-3\">
    <input type=\"checkbox\" name=\"{$column}\" id=\"{$column}\" value=\"1\" class=\"form-check-input\" {{ old('$column', \$${this->name}->$column ?? false) ? 'checked' : '' }}>
    <label for=\"{$column}\" class=\"form-check-label\">{$title}</label>
    @error('$column') <span class=\"text-danger\">{{ \$message }}</span> @enderror
</div>\n";
        } else {
            $form .= "
<div class=\"mb-3\">
    <label for=\"{$column}\" class=\"form-label\">{$title}</label>
    <input type=\"{$type}\" name=\"{$column}\" id=\"{$column}\" value=\"{{ old('$column', \$${this->name}->$column ?? '') }}\" class=\"form-control\">
    @error('$column') <span class=\"text-danger\">{{ \$message }}</span> @enderror
</div>\n";
        }
    }

    $replace = array_merge($this->buildReplacements(), [
        '{{tableHeader}}' => $tableHead,
        '{{tableBody}}'   => $tableBody,
        '{{viewRows}}'    => $viewRows,
        '{{form}}'        => $form,
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
private function _mapFieldType(string $column): string
{
    $col = collect($this->getColumns())->firstWhere('name', $column);

    return match ($col['type_name']) {
        'int', 'bigint'   => 'number',
        'bool'            => 'checkbox',
        'text'            => 'textarea',
        'date'            => 'date',
        'datetime'        => 'datetime-local',
        'timestamp'       => 'datetime-local',
        default           => 'text',
    };
}


    private function _buildClassName(): string
    {
        return Str::studly(Str::singular($this->table));
    }
}
