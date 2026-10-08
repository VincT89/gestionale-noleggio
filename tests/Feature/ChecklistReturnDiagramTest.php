<?php

namespace Tests\Feature;

use App\Livewire\Rentals\ChecklistForm;
use Barryvdh\DomPDF\Facade\Pdf;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChecklistReturnDiagramTest extends TestCase
{
    public static function printableTypes(): array
    {
        return ['rientro' => ['return'], 'modulo vuoto' => ['']];
    }

    #[DataProvider('printableTypes')]
    public function test_return_and_blank_pdfs_include_diagram_and_two_writing_lines(string $type): void
    {
        $data = $this->data($type);
        $html = view('pdfs.checklist', $data)->render();
        $this->assertStringContainsString('Schema danni al rientro', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertSame(2, substr_count($html, 'class="damage-detail-line"'));
        $this->assertStringContainsString('Dettagli dei danni segnati', $html);
        $this->assertStringContainsString('Firma cliente', $html);
        $this->assertStringContainsString('Firma noleggiante', $html);
        $pdf = Pdf::loadView('pdfs.checklist', $data)->setPaper('a4')->setOptions(['isRemoteEnabled' => false])->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($directory = getenv('CHECKLIST_PDF_AUDIT_DIR')) {
            file_put_contents($directory.'/checklist-'.($type ?: 'vuota').'.pdf', $pdf);
        }
    }

    public function test_pickup_checklist_does_not_gain_a_return_diagram(): void
    {
        $html = view('pdfs.checklist', $this->data('pickup'))->render();
        $this->assertStringNotContainsString('Schema danni al rientro', $html);
        $this->assertStringNotContainsString('class="damage-detail-line"', $html);
        $this->assertStringContainsString('Carta d’identità acquisita', $html);
    }

    public function test_return_pdf_version_invalidates_old_drafts_and_preserves_pickup_hashes(): void
    {
        $component = new ChecklistForm();
        $hash = new \ReflectionMethod($component, 'payloadHash');
        foreach (['pickup', 'return'] as $type) {
            $payload = ['base' => ['type' => $type, 'mileage' => 10000], 'json' => [], 'damages' => []];
            $oldHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $newHash = $hash->invoke($component, $payload);
            if ($type === 'return') $this->assertNotSame($oldHash, $newHash);
            else $this->assertSame($oldHash, $newHash);
            $this->assertSame($newHash, $hash->invoke($component, $payload));
        }
    }

    public function test_long_recorded_damage_descriptions_remain_in_the_pdf(): void
    {
        $data = $this->data('return');
        $data['payload']['damages'] = array_map(fn ($id) => [
            'area' => 'left', 'severity' => 'low', 'description' => 'Danno dimostrativo '.$id.': '.str_repeat('descrizione per il collaudo di impaginazione ', 6),
        ], range(1, 12));
        $data['payload']['json']['notes'] = 'Nota dimostrativa: dati usati solo per verificare la stampa.';
        $html = view('pdfs.checklist', $data)->render();
        $this->assertStringContainsString('Danno dimostrativo 12:', $html);
        $this->assertStringContainsString('Schema danni al rientro', $html);
        $pdf = Pdf::loadView('pdfs.checklist', $data)->setPaper('a4')->setOptions(['isRemoteEnabled' => false])->output();
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($directory = getenv('CHECKLIST_PDF_AUDIT_DIR')) file_put_contents($directory.'/checklist-danni-estesi.pdf', $pdf);
    }

    private function data(string $type): array
    {
        $blank = $type === '';
        $rental = (object) [
            'display_number_label' => $blank ? '' : 'DEMO',
            'customer' => (object) ['name' => $blank ? '' : 'Cliente dimostrativo'],
            'vehicle' => (object) ['brand' => $blank ? '' : 'Auto dimostrativa', 'model' => '', 'plate' => $blank ? '' : 'DEMO'],
            'organization' => (object) ['name' => $blank ? '' : 'Noleggiatore dimostrativo'],
        ];
        $checklist = new class($type, $rental) {
            public $id = '';
            public $replaces_checklist_id = null;
            public function __construct(public string $type, public object $rental) {}
            public function isLocked(): bool { return false; }
        };
        return ['checklist' => $checklist, 'payload' => [
            'base' => ['type' => $type, 'mileage' => $blank ? '' : 10000, 'fuel_percent' => $blank ? '' : 100, 'cleanliness' => $blank ? '' : 'good'],
            'json' => [], 'damages' => [],
        ], 'generated_at' => \Carbon\CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Rome'), 'signatures' => []];
    }
}
