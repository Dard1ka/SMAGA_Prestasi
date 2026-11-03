<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Settings\RemoveSignatureImage;
use App\Actions\Settings\ReplaceSignatureImage;
use App\Actions\Settings\UpdateLetterFormats;
use App\Actions\Settings\UpdateSigner;
use App\Domain\Letters\LetterNumber;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\File;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Tab "Surat & tanda tangan": the one signature + stamp image (PNG ≤ 1 MB, SECURITY §3m-5), signer name /
 * pangkat / NIP, and the two letter number formats. Signature actions need `signature.manage`, formats
 * `setting.manage`. The image itself is never shown here (no public or signed URL; TU uses it only through
 * letter generation) — only its metadata.
 * M1-A (lead, 2026-10-04): Tata Usaha types the complete number of every letter, so the two formats no longer
 * build numbers: they are only the example shown under TU's number field ("Contoh nomor surat" on this tab).
 */
final class Surat extends Component
{
    use RequiresRecentPassword, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $signature = null;

    public string $signerName = '';

    public string $signerRank = '';

    public string $signerNip = '';

    public string $formatSiswa = '';

    public string $formatGuru = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('update', Setting::class) || Gate::allows('manageSignature', Setting::class), 403);

        $this->signerName = (string) Setting::get(SettingKey::SignerName);
        $this->signerRank = (string) Setting::get(SettingKey::SignerRank);
        $this->signerNip = (string) Setting::get(SettingKey::SignerNip);
        $this->formatSiswa = (string) Setting::get(SettingKey::LetterFormatSiswa);
        $this->formatGuru = (string) Setting::get(SettingKey::LetterFormatGuru);
    }

    public function uploadSignature(ReplaceSignatureImage $replace): void
    {
        Gate::authorize('manageSignature', Setting::class);

        $this->validate(
            ['signature' => ['required', 'file', 'mimes:png', 'max:1024']],
            ['signature.mimes' => 'Pilih gambar PNG.', 'signature.max' => 'Ukuran maksimal 1 MB.'],
            ['signature' => 'gambar tanda tangan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $replace->handle($this->user(), $this->signature);
        $this->reset('signature');
        $this->dispatch('sth-toast', message: 'Tanda tangan baru aktif.', tone: 'success');
    }

    public function removeSignature(RemoveSignatureImage $remove): void
    {
        Gate::authorize('manageSignature', Setting::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $remove->handle($this->user());
        $this->dispatch('sth-modal-close', id: 'ttd-hapus');
        $this->dispatch('sth-toast', message: 'Tanda tangan dihapus. TU belum bisa membuat surat sampai ada gambar baru.', tone: 'warning');
    }

    public function saveSigner(UpdateSigner $update): void
    {
        Gate::authorize('manageSignature', Setting::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $update->handle($this->user(), $this->signerName, $this->signerRank, $this->signerNip);
        $this->dispatch('sth-toast', message: 'Data penandatangan tersimpan.', tone: 'success');
    }

    public function saveFormats(UpdateLetterFormats $update): void
    {
        Gate::authorize('update', Setting::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $update->handle($this->user(), $this->formatSiswa, $this->formatGuru);
        $this->dispatch('sth-toast', message: 'Contoh nomor surat tersimpan.', tone: 'success');
    }

    public function render(LetterNumber $letterNumber): View
    {
        $fileId = Setting::get(SettingKey::SignatureFileId);

        return view('livewire.admin.pengaturan.surat', [
            'signatureFile' => is_string($fileId) ? File::query()->find($fileId, ['id', 'original_name', 'size', 'created_at', 'owner_user_id']) : null,
            'principal' => User::query()->role(RoleName::KepalaSekolah->value)->canLogIn()->first(['id', 'name']),
            'exampleSiswa' => $this->example($letterNumber, $this->formatSiswa, 312),
            'exampleGuru' => $this->example($letterNumber, $this->formatGuru, 1680),
            'canSignature' => Gate::allows('manageSignature', Setting::class),
            'canFormats' => Gate::allows('update', Setting::class),
        ]);
    }

    private function example(LetterNumber $letterNumber, string $template, int $sequence): ?string
    {
        try {
            $letterNumber->assertValidTemplate(trim($template));

            return $letterNumber->format(trim($template), $sequence, CarbonImmutable::today());
        } catch (Throwable) {
            return null;
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function returnUrl(): string
    {
        return route('admin.pengaturan', ['tab' => 'surat']);
    }
}
