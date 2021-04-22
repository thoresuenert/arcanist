<?php declare(strict_types=1);

namespace Tests;

use Illuminate\Http\Request;
use Sassnowski\Arcanist\Field;
use Sassnowski\Arcanist\WizardStep;
use Sassnowski\Arcanist\AbstractWizard;

class InvalidateDependentFieldsTest extends WizardTestCase
{
    /** @test */
    public function it_unsets_a_single_dependent_field_if_the_field_it_depends_on_was_changed(): void
    {
        $repo = $this->createWizardRepository([
            '::normal-field-1::' => '::value-1::',
            '::dependent-field-1::' => '::value-2::',
        ], DependentStepWizard::class);
        $wizard = $this->createWizard(DependentStepWizard::class, repository: $repo);

        $wizard->update(Request::create('::uri::', 'POST', [
            '::normal-field-1::' => '::new-value::',
        ]), 1, 'regular-step');

        self::assertNull($repo->loadData($wizard)['::dependent-field-1::']);
    }

    /** @test */
    public function it_does_not_unset_a_dependent_field_if_the_field_it_depends_on_wasnt_changed(): void
    {
        $repo = $this->createWizardRepository([
            '::normal-field-1::' => '::value-1::',
            '::dependent-field-1::' => '::value-2::',
        ], DependentStepWizard::class);
        $wizard = $this->createWizard(DependentStepWizard::class, repository: $repo);

        $wizard->update(Request::create('::uri::', 'POST', [
            '::normal-field-1::' => '::value-1::',
        ]), 1, 'regular-step');

        self::assertEquals('::value-2::', $repo->loadData($wizard)['::dependent-field-1::']);
    }

    /** @test */
    public function it_unsets_a_dependent_field_if_one_of_its_dependencies_changed(): void
    {
        $repo = $this->createWizardRepository([
            '::normal-field-1::' => '::value-1::',
            '::dependent-field-1::' => '::value-2::',
            '::normal-field-2::' => '::value-3::',
        ], DependentStepWizard::class);
        $wizard = $this->createWizard(DependentStepWizard::class, repository: $repo);

        $wizard->update(Request::create('::uri::', 'POST', [
            '::normal-field-1::' => '::value-1::',
            '::normal-field-2::' => '::new-value::',
        ]), 1, 'regular-step');

        self::assertNull($repo->loadData($wizard)['::dependent-field-1::']);
    }

    /** @test */
    public function it_unsets_all_dependent_fields_if_a_common_dependency_changed(): void
    {
        $repo = $this->createWizardRepository([
            '::dependent-field-1::' => '::dependent-field-value::',
            '::dependent-field-2::' => '::dependent-field-2-value::',
            '::normal-field-2::' => '::normal-field-value::',
        ], MultiDependentStepWizard::class);
        $wizard = $this->createWizard(MultiDependentStepWizard::class, repository: $repo);

        $wizard->update(Request::create('::uri::', 'POST', [
            '::normal-field-2::' => '::new-value::',
        ]), 1, 'regular-step');

        self::assertNull($repo->loadData($wizard)['::dependent-field-1::']);
        self::assertNull($repo->loadData($wizard)['::dependent-field-2::']);
    }

    /** @test */
    public function it_unsets_all_dependent_fields_whose_dependency_was_changed(): void
    {
        $repo = $this->createWizardRepository([
            '::dependent-field-1::' => '::dependent-field-1-value::',
            '::dependent-field-3::' => '::dependent-field-3-value::',
            '::normal-field-1::' => '::normal-field-1-value::',
            '::normal-field-3::' => '::normal-field-3-value::',
        ], MultiDependentStepWizard::class);
        $wizard = $this->createWizard(MultiDependentStepWizard::class, repository: $repo);

        $wizard->update(Request::create('::uri::', 'POST', [
            '::normal-field-1::' => '::new-value::',
            '::normal-field-3::' => '::new-value::',
        ]), 1, 'regular-step');

        self::assertNull($repo->loadData($wizard)['::dependent-field-1::']);
        self::assertNull($repo->loadData($wizard)['::dependent-field-3::']);
    }
}

class DependentStepWizard extends AbstractWizard
{
    protected array $steps = [
        RegularStep::class,
        StepWithDependentField::class,
    ];
}

class MultiDependentStepWizard extends AbstractWizard
{
    protected array $steps = [
        RegularStep::class,
        StepWithDependentField::class,
        AnotherStepWithDependentField::class,
    ];
}

class RegularStep extends WizardStep
{
    public string $slug = 'regular-step';

    public function isComplete(): bool
    {
        return true;
    }

    protected function fields(): array
    {
        return [
            Field::make('::normal-field-1::'),
            Field::make('::normal-field-2::'),
            Field::make('::normal-field-3::'),
        ];
    }
}

class StepWithDependentField extends WizardStep
{
    public function isComplete(): bool
    {
        return $this->data('::dependent-field-1::') !== null;
    }

    protected function fields(): array
    {
        return [
            Field::make('::dependent-field-1::')
                ->dependsOn('::normal-field-1::', '::normal-field-2::'),
        ];
    }
}

class AnotherStepWithDependentField extends WizardStep
{
    public function isComplete(): bool
    {
        return $this->data('::dependent-field-2::') !== null;
    }

    protected function fields(): array
    {
        return [
            Field::make('::dependent-field-2::')
                ->dependsOn('::normal-field-2::'),

            Field::make('::dependent-field-3::')
                ->dependsOn('::normal-field-3::'),
        ];
    }
}
