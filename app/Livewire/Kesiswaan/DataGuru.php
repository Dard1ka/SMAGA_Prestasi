<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\UpdateGuruData;
use App\Enums\UserStatus;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kesiswaan › Data › Data guru (SV8, D-197, route `kesiswaan.guru.index`, `/kesiswaan/data-guru`; Admin opens the same
 * address from its own menu): every guru account that is not in the trash, any status, by name, with what the guru
 * letter prints under "Kepada" (Nama, NIP, Pangkat/golongan, Jabatan) and a marker for the empty ones; search by name
 * or NIP, the filter "Data belum lengkap", PER_PAGE rows a page.
 *
 * - "Ubah data" on a row: one dialog (Nama lengkap as printed, Pangkat/golongan, NIP, Jabatan) saved by
 *   App\Actions\Users\UpdateGuruData (the rules live there). Only an id this page listed (#[Locked] $shownIds; another
 *   → 404); the account must still be a guru (else a warning toast and a fresh list). A password confirmation from the
 *   last 15 minutes is asked BEFORE the dialog opens (the password page never throws typed values away) and checked
 *   again on save. D-128: save() acts only on what edit() stored and is a silent no-op without it; Batal, X, Esc and a
 *   click outside forget the question (forget()). Stale screens (CLAUDE.md §6): the data changed meanwhile → warning
 *   toast and the fields reloaded; no guru any more → the dialog closes with a warning toast; nothing typed differs →
 *   info toast "Tidak ada data yang diubah.".
 * - "Tambah guru": CreateStaffAccount::handleGuru(), the account of Admin › "Tambah pengguna" with the role guru only
 *   (never another role), the letter fields under the Data guru rules. Password confirmation like "Ubah data". The
 *   Admins are notified, as for every new staff account.
 *
 * The page needs UserPolicy::viewGuruData (`guru.update`: Kesiswaan, Admin), "Ubah data" ::updateGuruData, "Tambah guru"
 * ::createGuru (`guru.create`). Every action authorizes again.
 */
#[Title('Data guru')]
#[Layout('layouts::app', ['subtitle' => 'Nama, pangkat/golongan, NIP, dan jabatan guru seperti tercetak di surat tugas.'])]
final class DataGuru extends Component
{
    use RequiresRecentPassword, WithKesiswaanBadges, WithPagination;

    public const PER_PAGE = 25;

    /** The search reads at most this many characters (the box keeps what was typed, the query trims). */
    public const SEARCH_MAX = 100;

    /** The filter value "Data belum lengkap" (NIP, Pangkat/golongan or Jabatan empty). */
    public const FILTER_INCOMPLETE = 'belum-lengkap';

    /** Modal ids (one each per page). */
    public const DIALOG = 'ubah-data-guru';

    public const DIALOG_NEW = 'tambah-guru';

    public const MSG_SAVED = 'Data guru disimpan.';

    public const MSG_NOTHING = 'Tidak ada data yang diubah.';

    public const MSG_CREATED = 'Akun guru dibuat. Berikan nama pengguna dan kata sandi sementara langsung ke gurunya.';

    public const MSG_PASSWORD_MISMATCH = 'Konfirmasi kata sandi tidak cocok.';

    /** Labels of the empty-field marker, in the order of the columns. */
    public const MISSING_LABELS = ['nip' => 'NIP', 'rank' => 'Pangkat/golongan', 'position' => 'Jabatan'];

    /** Error keys of UpdateGuruData → the fields of the dialog. */
    private const FIELD_KEYS = [
        'name' => 'dataName',
        'rank' => 'dataRank',
        'nip' => 'dataNip',
        'position' => 'dataPosition',
    ];

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** '' = every guru; FILTER_INCOMPLETE = the ones with an empty NIP, Pangkat/golongan or Jabatan. */
    #[Url(as: 'data', except: '')]
    public string $filter = '';

    /** @var list<int> the guru accounts the last render listed ("Ubah data" accepts only these) */
    #[Locked]
    public array $shownIds = [];

    /** The guru account edit() asked about; null = no question. */
    #[Locked]
    public ?int $editId = null;

    /** @var array<string, string|null> UpdateGuruData::snapshot() of what the dialog showed */
    #[Locked]
    public array $shown = [];

    /** Page to come back to after the password confirmation. */
    #[Locked]
    public string $returnTo = '';

    public string $dataName = '';

    public string $dataRank = '';

    public string $dataNip = '';

    public string $dataPosition = '';

    /** @var array<string, string> the "Tambah guru" form */
    public array $new = [];

    public function mount(): void
    {
        Gate::authorize('viewGuruData', User::class);
        $this->returnTo = url()->full();
        $this->clampSearch();
        $this->filter = $this->filter === self::FILTER_INCOMPLETE ? $this->filter : '';
        $this->resetNewForm();
    }

    /** A new search starts on page 1; the typed text is kept as typed (render() trims it). */
    public function updatedQ(): void
    {
        $this->clampSearch();
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->filter = $this->filter === self::FILTER_INCOMPLETE ? $this->filter : '';
        $this->resetPage();
    }

    // "Ubah data" ----------------------------------------------------------------------------------------------------

    /** Ask step: the dialog for one guru this page listed (CLAUDE.md §7, D-128). */
    public function edit(int $userId): void
    {
        Gate::authorize('viewGuruData', User::class);
        abort_unless(in_array($userId, $this->shownIds, true), 404);
        $this->forget();

        $account = self::guru($userId);

        if ($account === null) {
            $this->gone();

            return;
        }

        Gate::authorize('updateGuruData', $account);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->editId = (int) $account->getKey();
        $this->showAccount($account);
        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    /** The dialog closed (Batal, X, Esc, a click outside): forget the question and the typed values. */
    public function forget(): void
    {
        $this->reset('editId', 'shown', 'dataName', 'dataRank', 'dataNip', 'dataPosition');
        $this->resetErrorBag();
    }

    /** "Simpan perubahan". Without a question asked by edit() there is nothing to save: it returns without a word. */
    public function save(UpdateGuruData $update): void
    {
        if ($this->editId === null) {
            return;
        }

        Gate::authorize('viewGuruData', User::class);

        $account = self::guru($this->editId);

        if ($account === null) {
            $this->gone();

            return;
        }

        Gate::authorize('updateGuruData', $account);

        if (UpdateGuruData::snapshot($account) !== $this->shown) {
            $this->changedMeanwhile($account);

            return;
        }

        $input = $this->input();
        $errors = $update->errors($account, $input);

        if ($errors !== []) {
            throw ValidationException::withMessages(self::fieldErrors($errors));
        }

        $this->resetErrorBag();

        if ($update->changedFields($account, $input) === []) {
            $this->dispatch('sth-toast', message: self::MSG_NOTHING, tone: 'info');

            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $update->handle($this->actor(), $account, $input, $this->shown);
        } catch (ValidationException $e) {
            $messages = array_map(fn (array $m): string => (string) ($m[0] ?? ''), $e->errors());
            $about = $messages[UpdateGuruData::KEY_GURU] ?? null;

            if ($about === UpdateGuruData::MSG_GONE) {
                $this->gone();

                return;
            }

            if ($about !== null) {
                $fresh = self::guru($this->editId);
                $fresh === null ? $this->gone() : $this->changedMeanwhile($fresh);

                return;
            }

            throw ValidationException::withMessages(self::fieldErrors($messages));
        }

        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch('sth-toast', message: self::MSG_SAVED, tone: 'success');
    }

    // "Tambah guru" --------------------------------------------------------------------------------------------------

    /** Opens the empty form after the password confirmation (typed values are never lost on the password page). */
    public function openCreate(): void
    {
        Gate::authorize('createGuru', User::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->resetNewForm();
        $this->resetErrorBag();
        $this->dispatch('sth-modal-open', id: self::DIALOG_NEW);
    }

    public function createGuru(CreateStaffAccount $create): void
    {
        Gate::authorize('createGuru', User::class);

        // Every problem at once (QA5 #65): the confirmation, the account and the letter fields in one answer.
        $errors = [];

        if ((string) ($this->new['password'] ?? '') !== (string) ($this->new['password_confirmation'] ?? '')) {
            $errors['new.password'] = self::MSG_PASSWORD_MISMATCH;
        }

        foreach (CreateStaffAccount::guruErrors($this->newInput()) as $key => $message) {
            $errors['new.'.$key] ??= $message;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->resetErrorBag();

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $create->handleGuru($this->actor(), $this->newInput());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ['new.'.$key => $messages])->all(),
            );
        }

        $this->resetNewForm();
        $this->resetPage();
        $this->dispatch('sth-modal-close', id: self::DIALOG_NEW);
        $this->dispatch('sth-toast', message: self::MSG_CREATED, tone: 'success');
    }

    public function render(): View
    {
        Gate::authorize('viewGuruData', User::class);

        $term = mb_substr(trim($this->q), 0, self::SEARCH_MAX);
        $nip = UpdateGuruData::digits($term);
        $byNip = $nip !== '' && ctype_digit($nip);
        // Wildcards are dropped, not escaped: the escape character differs between MySQL and SQLite, and no name has one.
        $like = '%'.str_replace(['%', '_', '\\'], '', mb_strtolower($term)).'%';

        $gurus = self::gurus()
            ->when($byNip, fn (Builder $q) => $q->whereHas('staffProfile', fn (Builder $p) => $p->whereRaw("REPLACE(nip, ' ', '') LIKE ?", ['%'.$nip.'%'])))
            ->when($term !== '' && ! $byNip, fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]))
            ->when($this->filter === self::FILTER_INCOMPLETE, fn (Builder $q) => self::incomplete($q))
            ->with('staffProfile')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['id', 'name', 'username', 'status', 'deleted_at']);

        $this->shownIds = array_map('intval', $gurus->getCollection()->modelKeys());
        $account = $this->editId === null ? null : self::guru($this->editId);

        return view('livewire.kesiswaan.data-guru', [
            'gurus' => $gurus,
            'rows' => $gurus->getCollection()->map(fn (User $user): array => self::row($user))->all(),
            'incompleteCount' => self::incomplete(self::gurus())->count(),
            'filtered' => $term !== '' || $this->filter !== '',
            'filterOptions' => ['' => 'Semua guru', self::FILTER_INCOMPLETE => 'Data belum lengkap'],
            'canEdit' => Gate::allows('viewGuruData', User::class),
            'canCreate' => Gate::allows('createGuru', User::class),
            'dialog' => $account === null ? null : [
                'stateLabel' => $account->status?->label() ?? '–',
                'stateTone' => self::statusTone($account->status),
                'username' => (string) ($account->username ?? '–'),
            ],
        ])->layoutData(['badges' => $this->kesiswaanBadges(), 'search' => false]);
    }

    /**
     * One table row: what the letter prints, the empty fields ("Belum ada: NIP, Jabatan").
     *
     * @return array{id: int, name: string, username: string, nip: ?string, rank: ?string, position: ?string, missing: list<string>, stateLabel: string, stateTone: string}
     */
    private static function row(User $user): array
    {
        $profile = $user->staffProfile;
        $values = [
            'nip' => filled($profile?->nip) ? (string) $profile->nip : null,
            'rank' => filled($profile?->rank) ? (string) $profile->rank : null,
            'position' => filled($profile?->position) ? (string) $profile->position : null,
        ];

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'username' => (string) ($user->username ?? ''),
            ...$values,
            'missing' => array_values(array_map(
                fn (string $key): string => self::MISSING_LABELS[$key],
                array_keys(array_filter($values, fn (?string $value): bool => $value === null)),
            )),
            'stateLabel' => $user->status?->label() ?? '–',
            'stateTone' => self::statusTone($user->status),
        ];
    }

    /** @return Builder<User> every guru account that is not in the trash (soft deletes) */
    private static function gurus(): Builder
    {
        return User::query()->gurus();
    }

    /**
     * The accounts whose letter would print a fallback: no staff_profiles row, or an empty NIP, rank or position.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function incomplete(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('staffProfile')
            ->orWhereHas('staffProfile', fn (Builder $p) => $p->where(function (Builder $empty): void {
                foreach (array_keys(self::MISSING_LABELS) as $column) {
                    $empty->orWhereNull($column)->orWhere($column, '');
                }
            })));
    }

    /** The colours of Admin › Pengguna & peran. */
    private static function statusTone(?UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Active => 'green',
            UserStatus::PendingActivation => 'orange',
            UserStatus::Disabled => 'red',
            default => 'gray',
        };
    }

    /** The dialog's fields and snapshot from the account as it is now. */
    private function showAccount(User $account): void
    {
        $profile = $account->staffProfile;

        $this->shown = UpdateGuruData::snapshot($account);
        $this->dataName = (string) $account->name;
        $this->dataRank = (string) ($profile?->rank ?? '');
        $this->dataNip = (string) ($profile?->nip ?? '');
        $this->dataPosition = (string) ($profile?->position ?? '');
    }

    /** The data changed meanwhile (another tab, an Admin): the fields show it now, the dialog stays open. */
    private function changedMeanwhile(User $account): void
    {
        $this->resetErrorBag();
        $this->showAccount($account);
        $this->dispatch('sth-toast', message: UpdateGuruData::MSG_CHANGED, tone: 'warning');
    }

    /** The account is no guru any more or was deleted: close, say so; the list redraws with this request. */
    private function gone(): void
    {
        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch('sth-toast', message: UpdateGuruData::MSG_GONE, tone: 'warning');
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'name' => $this->dataName,
            'rank' => $this->dataRank,
            'nip' => $this->dataNip,
            'position' => $this->dataPosition,
        ];
    }

    /** @return array<string, string> the form without the confirmation, the role never taken from the browser */
    private function newInput(): array
    {
        $input = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $this->new);
        unset($input['password_confirmation'], $input['role']);

        return $input;
    }

    private function resetNewForm(): void
    {
        $this->new = [
            'name' => '', 'username' => '', 'email' => '', 'honorific' => '',
            'nip' => '', 'rank' => '', 'position' => '',
            'password' => '', 'password_confirmation' => '',
        ];
    }

    /**
     * @param  array<string, string>  $errors  keys of UpdateGuruData
     * @return array<string, string> keys of this dialog
     */
    private static function fieldErrors(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $key => $message) {
            $mapped[self::FIELD_KEYS[$key] ?? 'dataName'] ??= $message;
        }

        return $mapped;
    }

    /** A guru account that is not in the trash, with its staff profile (null otherwise). */
    private static function guru(int $userId): ?User
    {
        /** @var User|null $account */
        $account = self::gurus()->whereKey($userId)->with('staffProfile')->first();

        return $account;
    }

    /** Only a text longer than the search reads is cut; the box keeps everything else as typed, spaces included. */
    private function clampSearch(): void
    {
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** Back from the password confirmation (RequiresRecentPassword): this page, with its search and filter. */
    protected function returnUrl(): string
    {
        return $this->returnTo !== '' ? $this->returnTo : route('kesiswaan.guru.index');
    }
}
