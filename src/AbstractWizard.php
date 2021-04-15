<?php declare(strict_types=1);

namespace Sassnowski\Arcanist;

use function event;
use function route;
use function config;
use function collect;
use function data_get;
use function redirect;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Sassnowski\Arcanist\Event\WizardLoaded;
use Sassnowski\Arcanist\Event\WizardSaving;
use Illuminate\Contracts\Support\Responsable;
use Sassnowski\Arcanist\Event\WizardFinished;
use Illuminate\Validation\ValidationException;
use Sassnowski\Arcanist\Event\WizardFinishing;
use Sassnowski\Arcanist\Contracts\ResponseRenderer;
use Sassnowski\Arcanist\Contracts\WizardRepository;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Sassnowski\Arcanist\Exception\UnknownStepException;
use Sassnowski\Arcanist\Exception\WizardNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class AbstractWizard
{
    use ValidatesRequests;

    /**
     * The display name of this wizard.
     */
    public static string $title = 'New wizard';

    /**
     * The slug of this wizard that gets used in the URL.
     */
    public static string $slug = 'new-wizard';

    /**
     * The description of the wizard that gets shown in the action card.
     */
    public static string $description = 'A brand new wizard';

    /**
     * The action that gets executed after the last step of the
     * wizard is completed.
     */
    public string $onCompleteAction = NullAction::class;

    protected string $cancelText = 'Cancel wizard';

    /**
     * The steps this wizard consists of.
     */
    protected array $steps = [];

    /**
     * The wizard's id in the database.
     */
    protected ?int $id = null;

    /**
     * The index of the currently active step.
     */
    protected int $currentStep = 0;

    /**
     * The wizard's stored data.
     */
    protected array $data = [];

    /**
     * URL to redirect to after the wizard was deleted.
     */
    protected string $redirectTo;

    /**
     * Additional data that was set during the request. This will
     * be merged with $data before the wizard gets saved.
     */
    protected array $additionalData = [];

    public function __construct(
        private WizardRepository $wizardRepository,
        private ResponseRenderer $responseRenderer
    ) {
        $this->redirectTo = config('arcanist.redirect_url', '/home');
        $this->steps = collect($this->steps)
            ->map(fn ($step, $i) => new $step($this, $i))
            ->all();
    }

    public static function startUrl(): string
    {
        $slug = static::$slug;

        return route("wizard.{$slug}.create");
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Data that should be shared with every step in this
     * wizard. This data gets merged with a step's view data.
     */
    public function sharedData(Request $request): array
    {
        return [];
    }

    /**
     * Renders the template of the first step of this wizard.
     */
    public function create(Request $request): Responsable | Response
    {
        return $this->renderStep($request, $this->steps[0]);
    }

    /**
     * Renders the template of the current step.
     *
     * @throws UnknownStepException
     */
    public function show(Request $request, int $wizardId, ?string $slug = null): Responsable | Response | RedirectResponse
    {
        $this->load($wizardId);

        if ($slug === null) {
            /** @var WizardStep $lastCompletedStep */
            $lastCompletedStep = collect($this->steps)
                ->last(fn (WizardStep $s) => $s->isComplete());

            return $this->responseRenderer->redirect(
                $this->steps[$lastCompletedStep->index() + 1],
                $this
            );
        }

        return $this->renderStep($request, $this->loadStep($slug));
    }

    /**
     * Handles the form submit for the first step in the workflow.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $step = $this->loadFirstStep();

        $result = $step->process($request);

        if (!$result->successful()) {
            return $this->responseRenderer->redirectWithError(
                $this->steps[0],
                $this,
                $result->error()
            );
        }

        $this->saveStepData($step, $result->payload(), $request);

        return $this->responseRenderer->redirect(
            $this->steps[1],
            $this
        );
    }

    /**
     * Handles the form submission of a step in an existing wizard.
     *
     * @throws UnknownStepException
     * @throws ValidationException
     */
    public function update(Request $request, int $wizardId, string $slug): RedirectResponse
    {
        $this->load($wizardId);

        $step = $this->loadStep($slug);

        $result = $step->process($request);

        if (!$result->successful()) {
            return $this->responseRenderer->redirectWithError(
                $this->steps[0],
                $this,
                $result->error()
            );
        }

        $this->saveStepData($step, $result->payload(), $request);

        if ($this->isLastStep()) {
            event(new WizardFinishing($this));

            $response = $this->onAfterComplete();

            event(new WizardFinished($this));

            return $response;
        }

        return $this->responseRenderer->redirect(
            $this->nextStep(),
            $this
        );
    }

    public function destroy(Request $request, int $wizardId): RedirectResponse
    {
        $this->load($wizardId);

        $this->beforeDelete($request);

        $this->wizardRepository->deletewizard($this);

        return redirect()->to($this->redirectTo());
    }

    public function setData(string $key, mixed $value): void
    {
        $this->additionalData[$key] = $value;
    }

    /**
     * Fetch any previously stored data for this wizard.
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->data, $this->additionalData);
        }

        return data_get($this->additionalData, $key) ?: data_get($this->data, $key, $default);
    }

    /**
     * Checks if this wizard already exists or is being created
     * for the first time.
     */
    public function exists(): bool
    {
        return $this->id !== null;
    }

    /**
     * Returns a summary about the current wizard and its steps.
     */
    public function summary(): array
    {
        $current = $this->currentStep();

        return [
            'id' => $this->id,
            'slug' => static::$slug,
            'title' => $this->title(),
            'cancelText' => $this->cancelText(),
            'steps' => collect($this->steps)->map(fn (WizardStep $step) => [
                'slug' => $step->slug,
                'isComplete' => $step->isComplete(),
                'name' => $step->name,
                'active' => $step->index() === $current->index(),
                'url' => $this->exists()
                    ? '/wizard/' . static::$slug . '/' . $this->id . '/' . $step->slug
                    : null,
            ])->all()
        ];
    }

    /**
     * Return a structured object of the wizard's data that will be
     * passed to the action after the wizard is completed.
     */
    public function transformWizardData(): mixed
    {
        return $this->data();
    }

    /**
     * Gets called after the last step in the wizard is finished.
     */
    protected function onAfterComplete(): RedirectResponse
    {
        return redirect()->to($this->redirectTo());
    }

    /**
     * Hook that gets called before the wizard is deleted. This is
     * a good place to free up any resources that might have been
     * reserved by the wizard.
     */
    protected function beforeDelete(Request $request): void
    {
        //
    }

    protected function redirectTo(): string
    {
        return $this->redirectTo;
    }

    /**
     * Returns the wizard's title that gets displayed in the frontend.
     */
    protected function title(): string
    {
        return static::$title;
    }

    protected function cancelText(): string
    {
        return $this->cancelText;
    }

    /**
     * @throws UnknownStepException
     */
    private function loadStep(string $slug): WizardStep
    {
        /** @var ?WizardStep $step */
        $step = collect($this->steps)
            ->first(fn (WizardStep $step) => $step->slug === $slug);

        if ($step === null) {
            throw new UnknownStepException(sprintf(
                'No step with slug [%s] exists for wizard [%s]',
                $slug,
                static::class,
            ));
        }

        $this->currentStep = $step->index();

        return $step;
    }

    private function load(int $wizardId): void
    {
        $this->id = $wizardId;

        try {
            $this->data = $this->wizardRepository->loadData($this);
        } catch (WizardNotFoundException $e) {
            throw new NotFoundHttpException(previous: $e);
        }

        event(new WizardLoaded($this));
    }

    private function renderStep(Request $request, WizardStep $step): Responsable | Response
    {
        return $this->responseRenderer->renderStep(
            $step,
            $this,
            $this->buildViewData($request, $step)
        );
    }

    private function buildViewData(Request $request, WizardStep $step): array
    {
        return array_merge(
            $step->viewData($request),
            $this->sharedData($request)
        );
    }

    private function saveStepData(WizardStep $step, array $data, Request $request): void
    {
        event(new WizardSaving($this));

        $step->beforeSaving($request, $data);

        $data = array_merge($data, $this->additionalData);

        $this->wizardRepository->saveData($this, $data);
    }

    private function nextStep(): WizardStep
    {
        return $this->steps[$this->currentStep + 1];
    }

    private function loadFirstStep(): WizardStep
    {
        return $this->steps[0];
    }

    private function currentStep(): WizardStep
    {
        return $this->steps[$this->currentStep ?? 0];
    }

    private function isLastStep(): bool
    {
        return $this->currentStep + 1 === count($this->steps);
    }
}