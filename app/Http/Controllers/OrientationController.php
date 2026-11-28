<?php

namespace App\Http\Controllers;

use App\Models\OrientationStudent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrientationController extends Controller
{
    public function loginForm() { return view('orientation.login'); }

    public function login(Request $request)
    {
        $data = $request->validate(['identifier' => ['required'], 'password' => ['required']]);
        $identifier = mb_strtolower(trim($data['identifier']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$identifier])->orWhereRaw('LOWER(student_number) = ?', [$identifier])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['identifier' => 'Identifiant ou mot de passe incorrect.'])->withInput();
        }
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->route($user->role === 'rup' ? 'orientation.rup' : 'orientation.student');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    public function rup()
    {
        abort_unless(Auth::user()?->role === 'rup', 403);
        return view('orientation.rup', ['students' => OrientationStudent::query()->orderBy('name')->get()]);
    }

    public function student()
    {
        $user = Auth::user();
        abort_unless($user?->role === 'etudiant', 403);
        $student = OrientationStudent::where('user_id', $user->id)->firstOrFail();
        return view('orientation.student', compact('student'));
    }

    public function import(Request $request)
    {
        abort_unless(Auth::user()?->role === 'rup', 403);
        $request->validate(['fiche' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        if (! Schema::hasTable('orientation_students') || ! Schema::hasColumn('users', 'student_number')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $handle = fopen($request->file('fiche')->getRealPath(), 'r');
        if (! $handle) return back()->withErrors(['fiche' => 'Impossible de lire le fichier.']);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) $rows[] = $row;
        fclose($handle);

        $headerIndex = null; $header = null;
        foreach ($rows as $index => $row) {
            $labels = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $row);
            if (in_array('matricule', $labels, true) && collect($labels)->contains(fn ($label) => str_contains($label, 'nom') && str_contains($label, 'prénoms'))) {
                $headerIndex = $index; $header = $row; break;
            }
        }
        if ($headerIndex === null) return back()->withErrors(['fiche' => 'Entête ESI Matricule / NOM et Prénoms introuvable.']);

        $studentIndex = array_search('Matricule', $header, true);
        $nameIndex = collect($header)->search(fn ($label) => str_contains(mb_strtolower((string) $label), 'nom') && str_contains(mb_strtolower((string) $label), 'prénoms'));
        $ueIndexes = [];
        for ($ue = 1; $ue <= 6; $ue++) {
            $index = collect($header)->search(fn ($label) => str_contains(mb_strtolower((string) $label), "moyenne ue{$ue}"));
            if (! is_int($index)) return back()->withErrors(['fiche' => "Moyenne UE{$ue} introuvable."]);
            $ueIndexes[$ue] = $index;
        }

        $created = 0;
        $credentials = [];
        DB::transaction(function () use ($rows, $headerIndex, $studentIndex, $nameIndex, $ueIndexes, &$created, &$credentials) {
            for ($index = $headerIndex + 2; $index < count($rows); $index++) {
                $row = $rows[$index];
                $number = trim((string) ($row[$studentIndex] ?? ''));
                $name = trim((string) ($row[$nameIndex] ?? ''));
                if (! $number || ! $name) continue;
                $grades = [];
                $valid = true;
                for ($ue = 1; $ue <= 6; $ue++) {
                    $grade = (float) str_replace(',', '.', trim((string) ($row[$ueIndexes[$ue]] ?? '')));
                    if ($grade < 0 || $grade > 20) { $valid = false; break; }
                    $grades[] = round($grade, 2);
                }
                if (! $valid) continue;

                $student = OrientationStudent::where('student_number', $number)->first();
                if (! $student) {
                    $password = Str::random(12);
                    $email = mb_strtolower(preg_replace('/[^a-zA-Z0-9._+-]/', '.', $number)).'@students.test';
                    $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => 'etudiant', 'student_number' => $number]);
                    OrientationStudent::create(['user_id' => $user->id, 'student_number' => $number, 'name' => $name, 's1' => $grades, 's2' => $grades, 'choices' => []]);
                    $credentials[] = ['student_number' => $number, 'password' => $password];
                    $created++;
                } else {
                    $student->update(['name' => $name, 's1' => $grades, 's2' => $grades]);
                }
            }
        });

        return back()->with('success', "Import terminé: {$created} nouveau(x) compte(s) étudiant créé(s).")->with('created_credentials', $credentials);
    }

    public function choices(Request $request, OrientationStudent $student)
    {
        abort_unless(Auth::user()?->role === 'rup', 403);
        $data = $request->validate(['choice_1' => ['required', 'in:EIT,INFO'], 'choice_2' => ['required', 'in:EIT,INFO', 'different:choice_1']]);
        $student->update(['choices' => [$data['choice_1'], $data['choice_2']]]);
        $this->recalculateEstimates();
        return back()->with('success', 'Vœux enregistrés et estimation recalculée.');
    }

    public function studentChoices(Request $request)
    {
        $user = Auth::user();
        abort_unless($user?->role === 'etudiant', 403);
        $student = OrientationStudent::where('user_id', $user->id)->firstOrFail();
        $data = $request->validate([
            'choice_1' => ['required', 'in:EIT,INFO'],
            'choice_2' => ['required', 'in:EIT,INFO', 'different:choice_1'],
        ]);
        $student->update(['choices' => [$data['choice_1'], $data['choice_2']]]);
        $this->recalculateEstimates();
        return back()->with('success', 'Vos deux vœux ont été enregistrés définitivement.');
    }

    public function estimate()
    {
        abort_unless(Auth::user()?->role === 'rup', 403);
        $this->recalculateEstimates();
        return back()->with('success', 'Estimation recalculée selon les scores, les vœux et les capacités.');
    }

    private function recalculateEstimates(): void
    {
        $weights = ['EIT' => [15,25,15,25,10,10], 'INFO' => [20,15,35,10,10,10]];
        $capacities = ['EIT' => 3, 'INFO' => 4];
        $students = OrientationStudent::all();

        $scoreFor = function (OrientationStudent $student, string $speciality) use ($weights): float {
            return collect($student->s1 ?? [])
                ->map(fn ($grade, $index) => (float) $grade * $weights[$speciality][$index] / 100)
                ->sum();
        };

        $held = ['EIT' => collect(), 'INFO' => collect()];
        $nextChoice = $students->mapWithKeys(fn ($student) => [$student->id => 0])->all();
        $pending = $students->pluck('id')->all();

        while ($pending !== []) {
            $proposals = ['EIT' => [], 'INFO' => []];
            $rejected = [];

            foreach ($pending as $studentId) {
                $student = $students->firstWhere('id', $studentId);
                $choices = $student->choices ?? [];
                $choiceIndex = $nextChoice[$studentId];
                if (! isset($choices[$choiceIndex])) continue;

                $speciality = $choices[$choiceIndex];
                $nextChoice[$studentId]++;
                $proposals[$speciality][] = $student;
            }

            foreach (['EIT', 'INFO'] as $speciality) {
                $candidates = $held[$speciality]->merge($proposals[$speciality])->sort(function ($left, $right) use ($scoreFor, $speciality) {
                    $scoreDifference = $scoreFor($right, $speciality) <=> $scoreFor($left, $speciality);
                    return $scoreDifference !== 0
                        ? $scoreDifference
                        : strcmp($left->student_number, $right->student_number);
                })->values();

                $held[$speciality] = $candidates->take($capacities[$speciality]);
                foreach ($candidates->slice($capacities[$speciality]) as $student) {
                    $rejected[] = $student->id;
                }
            }

            $pending = collect($rejected)->filter(function ($studentId) use ($nextChoice, $students) {
                $student = $students->firstWhere('id', $studentId);
                $choices = $student->choices ?? [];
                return isset($choices[$nextChoice[$studentId]]);
            })->unique()->values()->all();
        }

        foreach ($students as $student) {
            $estimate = null;
            foreach (['EIT', 'INFO'] as $speciality) {
                if ($held[$speciality]->contains('id', $student->id)) {
                    $estimate = $speciality;
                    break;
                }
            }
            $student->update(['estimate' => $estimate]);
        }
    }
}
