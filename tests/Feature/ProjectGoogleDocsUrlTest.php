<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentTypeCheck;
use App\Support\GoogleDocsFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectGoogleDocsUrlTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '1-wX4DWNISgrL822lo__G-JhVav3pfT3siBrCwBoOOo4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_create_stores_the_id_from_a_full_url(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'PRD',
                'google_docs_id' => 'https://docs.google.com/document/d/'.self::ID.'/edit?tab=t.0',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(self::ID, Project::sole()->google_docs_id);
    }

    public function test_create_still_accepts_a_bare_id(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'PRD', 'google_docs_id' => self::ID])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(self::ID, Project::sole()->google_docs_id);
    }

    public function test_create_rejects_a_non_docs_url(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'name' => 'PRD',
                'google_docs_id' => 'https://docs.google.com/spreadsheets/d/'.self::ID.'/edit',
            ])
            ->call('create')
            ->assertHasFormErrors(['google_docs_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_document_is_optional(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'Tanpa dokumen', 'google_docs_id' => ''])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Project::sole()->google_docs_id);
    }

    public function test_edit_shows_the_url_and_saves_the_id(): void
    {
        $project = Project::create(['name' => 'PRD', 'google_docs_id' => self::ID]);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormSet(['google_docs_id' => 'https://docs.google.com/document/d/'.self::ID.'/edit'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::ID, $project->fresh()->google_docs_id);
    }

    public function test_office_file_with_a_docs_url_is_refused_with_the_type_check_message(): void
    {
        $this->fakeTypeCheck(GoogleDocsFile::problem('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'DOCX', 'google_docs_id' => 'https://docs.google.com/document/d/'.self::ID.'/edit'])
            ->call('create')
            ->assertHasFormErrors(['google_docs_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_bare_id_of_a_spreadsheet_is_refused(): void
    {
        $this->fakeTypeCheck(GoogleDocsFile::problem('application/vnd.google-apps.spreadsheet'));

        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'Sheet', 'google_docs_id' => self::ID])
            ->call('create')
            ->assertHasFormErrors(['google_docs_id']);

        $this->assertSame(0, Project::count());
    }

    public function test_type_check_receives_the_extracted_id(): void
    {
        $seen = $this->fakeTypeCheck(null);

        Livewire::test(CreateProject::class)
            ->fillForm(['name' => 'PRD', 'google_docs_id' => 'https://docs.google.com/document/d/'.self::ID.'/edit?tab=t.0'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([self::ID], $seen->ids);
    }

    private function fakeTypeCheck(?string $problem): object
    {
        $fake = new class($problem) extends DocumentTypeCheck
        {
            public array $ids = [];

            public function __construct(private ?string $result) {}

            public function problem(?User $user, string $fileId): ?string
            {
                $this->ids[] = $fileId;

                return $this->result;
            }
        };
        $this->app->instance(DocumentTypeCheck::class, $fake);

        return $fake;
    }
}
