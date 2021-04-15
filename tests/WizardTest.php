<?php declare(strict_types=1);

namespace Tests;

use Generator;
use Illuminate\Http\Request;
use Sassnowski\Arcanist\StepResult;
use Sassnowski\Arcanist\WizardStep;
use Illuminate\Testing\TestResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Sassnowski\Arcanist\AbstractWizard;
use Sassnowski\Arcanist\Event\WizardLoaded;
use Sassnowski\Arcanist\Event\WizardSaving;
use Sassnowski\Arcanist\Event\WizardFinished;
use Illuminate\Validation\ValidationException;
use Sassnowski\Arcanist\Event\WizardFinishing;
use Sassnowski\Arcanist\Contracts\ResponseRenderer;
use Sassnowski\Arcanist\Renderer\FakeResponseRenderer;
use Sassnowski\Arcanist\Exception\UnknownStepException;
use Sassnowski\Arcanist\Repository\FakeWizardRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WizardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        $_SERVER['__beforeSaving.called'] = 0;
        $_SERVER['__onAfterComplete.called'] = 0;
        $_SERVER['__beforeDelete.called'] = 0;
    }

    /** @test */
    public function it_renders_the_first_step_in_an_wizard(): void
    {
        $renderer = new FakeResponseRenderer();
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            $renderer
        );

        $wizard->create(new Request());

        self::assertTrue($renderer->stepWasRendered(TestStep::class));
    }

    /** @test */
    public function it_throws_an_exception_if_no_step_exists_for_the_provided_slug(): void
    {
        $this->expectException(UnknownStepException::class);

        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->show(new Request(), 1, '::step-slug::');
    }

    /** @test */
    public function it_gets_the_view_data_from_the_step(): void
    {
        $renderer = new FakeResponseRenderer();
        $wizard = new TestWizard($this->createWizardRepository(), $renderer);

        $wizard->show(new Request(), 1, 'step-with-view-data');

        self::assertTrue($renderer->stepWasRendered(TestStepWithViewData::class, [
            'foo' => 'bar'
        ]));
    }

    /** @test */
    public function it_rejects_an_invalid_form_request(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create('::url::', 'POST', [
            'first_name' => '::first-name::',
        ]);
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->store($request);
    }

    /** @test */
    public function it_handles_the_form_submit_for_the_first_step_in_the_workflow(): void
    {
        $request = Request::create('::url::', 'POST', [
            'first_name' => '::first-name::',
            'last_name' => '::last-name::',
        ]);
        $repo = new FakeWizardRepository();
        $wizard = new TestWizard($repo, new FakeResponseRenderer());

        $wizard->store($request);

        self::assertEquals(
            [
                'first_name' => '::first-name::',
                'last_name' => '::last-name::',
            ],
            $repo->loadData($wizard)
        );
    }

    /** @test */
    public function it_renders_a_step_for_an_existing_wizard_using_the_saved_data(): void
    {
        $repo = new FakeWizardRepository([
            TestWizard::class => [
                1 => [
                    'first_name' => '::first-name::',
                    'last_name' => '::last-name::',
                ],
            ],
        ]);
        $renderer = new FakeResponseRenderer();
        $wizard = new TestWizard($repo, $renderer);

        $wizard->show(new Request(), 1, 'step-name');

        self::assertTrue($renderer->stepWasRendered(TestStep::class, [
            'first_name' => '::first-name::',
            'last_name' => '::last-name::',
        ]));
    }

    /** @test */
    public function it_handles_the_form_submission_for_a_step_in_an_existing_wizard(): void
    {
        $repo = $this->createWizardRepository([
            'first_name' => '::old-first-name::',
            'last_name' => '::old-last-name::',
        ]);
        $request = Request::create('::url::', 'PUT', [
            'first_name' => '::new-first-name::',
            'last_name' => '::old-last-name::',
        ]);
        $wizard = new TestWizard($repo, new FakeResponseRenderer());

        $wizard->update($request, 1, 'step-name');

        self::assertEquals([
            'first_name' => '::new-first-name::',
            'last_name' => '::old-last-name::',
        ], $repo->loadData($wizard));
    }

    /** @test */
    public function it_redirects_to_the_next_step_after_submitting_a_new_wizard(): void
    {
        $renderer = new FakeResponseRenderer();
        $request = Request::create('::url::', 'PUT', [
            'first_name' => '::new-first-name::',
            'last_name' => '::old-last-name::',
        ]);
        $wizard = new TestWizard($this->createWizardRepository(), $renderer);

        $wizard->store($request);

        self::assertTrue($renderer->didRedirectTo(TestStepWithViewData::class));
    }

    /** @test */
    public function it_redirects_to_the_next_step_after_submitting_an_existing_wizard(): void
    {
        $renderer = new FakeResponseRenderer();
        $request = Request::create('::url::', 'PUT', [
            'first_name' => '::new-first-name::',
            'last_name' => '::old-last-name::',
        ]);
        $wizard = new TestWizard($this->createWizardRepository(), $renderer);

        $wizard->update($request, 1, 'step-name');

        self::assertTrue($renderer->didRedirectTo(TestStepWithViewData::class));
    }

    /** @test */
    public function it_returns_the_wizards_title(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertEquals('::wizard-name::', $summary['title']);
    }

    /**
     * @test
     * @dataProvider idProvider
     */
    public function it_returns_the_wizards_id_in_the_summary(?int $id): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        if ($id !== null) {
            $wizard->setId($id);
        }

        $summary = $wizard->summary();

        self::assertEquals($id, $summary['id']);
    }

    public function idProvider(): Generator
    {
        yield from [
            'no id' => [null],
            'with id' => [5],
        ];
    }

    /** @test */
    public function it_returns_the_wizards_slug_in_the_summary(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertEquals($wizard::$slug, $summary['slug']);
    }

    /** @test */
    public function it_returns_the_slug_of_each_step_in_the_summary(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertEquals('step-name', $summary['steps'][0]['slug']);
        self::assertEquals('step-with-view-data', $summary['steps'][1]['slug']);
    }

    /** @test */
    public function it_renders_information_about_the_completion_of_each_step(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertTrue($summary['steps'][0]['isComplete']);
        self::assertFalse($summary['steps'][1]['isComplete']);
    }

    /** @test */
    public function it_renders_the_title_of_each_step_in_the_summary(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertEquals('::step-1-name::', $summary['steps'][0]['name']);
        self::assertEquals('::step-2-name::', $summary['steps'][1]['name']);
    }

    /** @test */
    public function it_marks_the_first_step_as_active_on_the_create_route(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );
        $wizard->create(new Request());

        $summary = $wizard->summary();

        self::assertTrue($summary['steps'][0]['active']);
        self::assertFalse($summary['steps'][1]['active']);
    }

    /** @test */
    public function it_marks_the_current_step_active_for_the_show_route(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );
        $wizard->show(new Request(), 1, 'step-with-view-data');

        $summary = $wizard->summary();

        self::assertFalse($summary['steps'][0]['active']);
        self::assertTrue($summary['steps'][1]['active']);
    }

    /**
     * @test
     * @dataProvider wizardExistsProvider
     */
    public function it_can_check_if_an_existing_wizard_is_being_edited(?int $id, bool $expected): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        if ($id !== null) {
            $wizard->setId($id);
        }

        self::assertEquals($expected, $wizard->exists());
    }

    public function wizardExistsProvider(): Generator
    {
        yield from [
            'does not exist' => [null, false],
            'exists' => [1, true],
        ];
    }

    /** @test */
    public function it_includes_the_link_to_the_step_in_the_summary(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );
        $wizard->setId(1);

        $summary = $wizard->summary();

        self::assertEquals('/wizard/wizard-name/1/step-name', $summary['steps'][0]['url']);
        self::assertEquals('/wizard/wizard-name/1/step-with-view-data', $summary['steps'][1]['url']);
    }

    /** @test */
    public function it_does_not_include_the_step_urls_if_the_wizard_does_not_exist(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertNull($summary['steps'][0]['url']);
        self::assertNull($summary['steps'][1]['url']);
    }

    /** @test */
    public function it_includes_the_wizards_cancel_button_text_in_the_summary(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $summary = $wizard->summary();

        self::assertEquals('::cancel-text::', $summary['cancelText']);
    }

    /**
     * @test
     * @dataProvider sharedDataProvider
     */
    public function it_includes_shared_data_in_the_view_response(callable $callwizard): void
    {
        $renderer = new FakeResponseRenderer();
        $repository = $this->createWizardRepository(wizardClass:  SharedDataWizard::class);
        $wizard = new SharedDataWizard(
            $repository,
            $renderer
        );

        $callwizard($wizard);

        self::assertTrue($renderer->stepWasRendered(TestStep::class, [
            'first_name' => '',
            'last_name' => '',
            'shared_1' => '::shared-1::',
            'shared_2' => '::shared-2::',
        ]));
    }

    public function sharedDataProvider(): Generator
    {
        yield from [
            'create' => [
                function (AbstractWizard $wizard) {
                    $wizard->create(new Request());
                }
            ],

            'show' => [
                function (AbstractWizard $wizard) {
                    $wizard->show(new Request(), 1, 'step-name');
                }
            ]
        ];
    }

    /**
     * @test
     * @dataProvider beforeSaveProvider
     */
    public function it_calls_the_before_save_hook_of_the_step_before_saving_the_data(callable $callwizard): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $callwizard($wizard);

        self::assertEquals(1, $_SERVER['__beforeSaving.called']);
    }

    public function beforeSaveProvider(): Generator
    {
        $validRequest = Request::create('::uri::', 'POST', [
            'first_name' => '::first-name::',
            'last_name' => '::last-name::',
        ]);

        yield from  [
            'store' => [
                fn (AbstractWizard $wizard) => $wizard->store($validRequest)
            ],
            'update' => [
                fn (AbstractWizard $wizard) => $wizard->update($validRequest, 1, 'step-name')
            ]
        ];
    }

    /** @test */
    public function it_fires_an_event_after_the_last_step_of_the_wizard_was_finished(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->update(new Request(), 1, 'step-with-view-data');

        Event::assertDispatched(
            WizardFinishing::class,
            fn (WizardFinishing $event) => $event->wizard === $wizard
        );
    }

    /** @test */
    public function it_fires_an_event_after_the_onComplete_callback_was_ran(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->update(new Request(), 1, 'step-with-view-data');

        Event::assertDispatched(
            WizardFinished::class,
            fn (WizardFinished $event) => $event->wizard === $wizard
        );
    }

    /** @test */
    public function it_calls_the_on_after_complete_hook_of_the_wizard(): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->update(new Request(), 1, 'step-with-view-data');

        self::assertEquals(1, $_SERVER['__onAfterComplete.called']);
    }

    /** @test */
    public function it_stores_additional_data_that_was_set_during_the_request(): void
    {
        $repo = $this->createWizardRepository();
        $wizard = new TestWizard(
            $repo,
            new FakeResponseRenderer()
        );

        $wizard->update(new Request(), 1, 'step-with-view-data');

        self::assertEquals(['::key::' => '::value::'], $repo->loadData($wizard));
    }

    /**
     * @test
     * @dataProvider beforeSaveProvider
     */
    public function it_fires_an_event_before_the_wizard_gets_saved(callable $callwizard): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $callwizard($wizard);

        Event::assertDispatched(
            WizardSaving::class,
            fn (WizardSaving $e) => $e->wizard === $wizard
        );
    }

    /**
     * @test
     * @dataProvider afterSaveProvider
     */
    public function it_fires_an_event_after_an_wizard_was_loaded(callable $callwizard): void
    {
        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $callwizard($wizard);

        Event::assertDispatched(
            WizardLoaded::class,
            fn (WizardLoaded $e) => $e->wizard === $wizard
        );
    }

    public function afterSaveProvider(): Generator
    {
        yield from [
            'update' => [
                function (AbstractWizard $wizard) {
                    $wizard->update(new Request(), 1, 'step-with-view-data');
                },
            ],

            'show' => [
                function (AbstractWizard $wizard) {
                    $wizard->show(new Request(), 1, 'step-with-view-data');
                },
            ],
        ];
    }

    /** @test */
    public function it_can_be_deleted(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $wizard->destroy(new Request(), 1);

        $wizard->show(new Request(), 1, 'step-name');
    }

    /** @test */
    public function it_redirects_to_the_default_route_after_the_wizard_has_been_deleted(): void
    {
        config(['arcanist.redirect_url' => '::redirect-url::']);

        $wizard = new TestWizard(
            $this->createWizardRepository(),
            new FakeResponseRenderer()
        );

        $response = new TestResponse($wizard->destroy(new Request(), 1));

        $response->assertRedirect('::redirect-url::');
    }

    /** @test */
    public function it_redirects_to_the_correct_url_if_the_default_url_was_overwritten(): void
    {
        $wizard = new SharedDataWizard(
            $this->createWizardRepository(wizardClass: SharedDataWizard::class),
            new FakeResponseRenderer()
        );

        $response = new TestResponse($wizard->destroy(new Request(), 1));

        $response->assertRedirect('::other-route::');
    }

    /**
     * @test
     * @dataProvider resumeWizardProvider
     */
    public function it_redirects_to_the_next_uncompleted_step_if_no_step_slug_was_given(callable $createwizard, string $expectedStep): void
    {
        $renderer = new FakeResponseRenderer();
        $wizard = $createwizard($renderer);

        $wizard->show(new Request(), 1);

        self::assertTrue($renderer->didRedirectTo($expectedStep));
    }

    public function resumeWizardProvider(): Generator
    {
        yield from [
            [
                function (ResponseRenderer $renderer) {
                    return new TestWizard(
                        $this->createWizardRepository(),
                        $renderer
                    );
                },
                TestStepWithViewData::class,
            ],
            [
                function (ResponseRenderer $renderer) {
                    return new MultiStepWizard(
                        $this->createWizardRepository(wizardClass: MultiStepWizard::class),
                        $renderer
                    );
                },
                TestStepWithViewData::class,
            ]
        ];
    }

    /**
     * @test
     * @dataProvider errorWizardProvider
     */
    public function it_redirects_to_the_same_step_with_an_error_if_the_step_was_not_completed_successfully(callable $callWizard): void
    {
        $renderer = new FakeResponseRenderer();
        $wizard = new ErrorWizard(
            $this->createWizardRepository(wizardClass: ErrorWizard::class),
            $renderer
        );

        $callWizard($wizard);

        self::assertTrue(
            $renderer->didRedirectWithError(ErrorStep::class, '::error-message::')
        );
    }

    public function errorWizardProvider()
    {
        yield from [
            'store' => [
                function (AbstractWizard $wizard) {
                    $wizard->store(new Request());
                }
            ],

            'update' => [
                function (AbstractWizard $wizard) {
                    $wizard->update(new Request(), 1, '::error-step::');
                }
            ]
        ];
    }

    private function createWizardRepository(array $data = [], ?string $wizardClass = null)
    {
        return new FakeWizardRepository([
            $wizardClass ?: TestWizard::class => [
                1 => $data
            ],
        ]);
    }
}

class TestWizard extends AbstractWizard
{
    public static string $slug = 'wizard-name';
    public static string $title = '::wizard-name::';

    protected array $steps = [
        TestStep::class,
        TestStepWithViewData::class,
    ];

    protected function onAfterComplete(): RedirectResponse
    {
        $_SERVER['__onAfterComplete.called']++;

        return redirect()->back();
    }

    protected function beforeDelete(Request $request): void
    {
        $_SERVER['__beforeDelete.called']++;
    }

    protected function cancelText(): string
    {
        return '::cancel-text::';
    }
}

class MultiStepWizard extends AbstractWizard
{
    protected array $steps = [
        TestStep::class,
        DummyStep::class,
        TestStepWithViewData::class
    ];
}

class SharedDataWizard extends AbstractWizard
{
    protected array $steps = [
        TestStep::class,
    ];

    public function sharedData(Request $request): array
    {
        return [
            'shared_1' => '::shared-1::',
            'shared_2' => '::shared-2::',
        ];
    }

    protected function onAfterComplete(): RedirectResponse
    {
        return redirect();
    }

    protected function redirectTo(): string
    {
        return '::other-route::';
    }
}

class ErrorWizard extends AbstractWizard
{
    protected array $steps = [
        ErrorStep::class,
    ];
}

class TestStep extends WizardStep
{
    public string $name = '::step-1-name::';
    public string $slug = 'step-name';

    protected function rules(): array
    {
        return [
            'first_name' => 'required',
            'last_name' => 'required',
        ];
    }

    public function viewData(Request $request): array
    {
        return [
            'first_name' => $this->data('first_name'),
            'last_name' => $this->data('last_name'),
        ];
    }

    public function isComplete(): bool
    {
        return true;
    }

    public function beforeSaving(Request $request, array $data): void
    {
        $_SERVER['__beforeSaving.called']++;
    }
}

class TestStepWithViewData extends WizardStep
{
    public string $name = '::step-2-name::';
    public string $slug = 'step-with-view-data';

    public function viewData(Request $request): array
    {
        return ['foo' => 'bar'];
    }

    public function beforeSaving(Request $request, array $data): void
    {
        $this->setData('::key::', '::value::');
    }

    public function isComplete(): bool
    {
        return false;
    }
}

class DummyStep extends WizardStep
{
    public function isComplete(): bool
    {
        return true;
    }
}

class ErrorStep extends WizardStep
{
    public string $slug = '::error-step::';

    public function isComplete(): bool
    {
        return false;
    }

    protected function handle(Request $request, array $payload): StepResult
    {
        return $this->error('::error-message::');
    }
}