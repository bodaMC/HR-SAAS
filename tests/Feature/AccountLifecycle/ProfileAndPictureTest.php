<?php

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support.php';
uses(RefreshDatabase::class);

function lc_img(string $type, int $w, int $h, string $name = 'a'): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    $f = tempnam(sys_get_temp_dir(), 'img');
    match ($type) {
        'png' => imagepng($im, $f), 'webp' => imagewebp($im, $f), default => imagejpeg($im, $f)
    };

    return new UploadedFile($f, "$name.$type", null, null, true);
}

beforeEach(function () {
    Storage::fake('local');
});

test('My Profile: an employee edits only the phone; every other field is rejected', function () {
    $w = lc_world();
    lc_call($this, $w['empT'], 'PUT', '/api/v1/me/profile', ['phone' => '+20 100 000 0001'])->assertOk();
    expect(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->phone)->toBe('+20 100 000 0001');
    foreach (['first_name', 'last_name', 'email', 'department_id', 'job_title_id', 'manager_id', 'employment_status', 'hire_date', 'national_id', 'user_id', 'salary', 'role'] as $f) {
        lc_call($this, $w['empT'], 'PUT', '/api/v1/me/profile', ['phone' => '1', $f => 'x'])->assertStatus(422);
    }
    $show = lc_call($this, $w['empT'], 'GET', '/api/v1/me/profile')->assertOk()->json('data');
    expect($show['employee'])->toHaveKey('phone');
    // an account with no linked employee gets a clear 404, not another person's data
    [$orphan, $orphanT] = lc_user($w['c'], 'employee', ['email' => 'orphan@a.test']);
    lc_call($this, $orphanT, 'PUT', '/api/v1/me/profile', ['phone' => '+20 100 000 0002'])->assertStatus(404);
});

test('picture upload: JPEG, PNG and WebP are re-encoded into a private tenant path; replace and remove clean up', function () {
    $w = lc_world();
    $first = null;
    foreach (['jpg', 'png', 'webp'] as $t) {
        lc_call($this, $w['empT'], 'POST', '/api/v1/me/profile/picture', ['picture' => lc_img($t, 300, 200)])->assertOk()->assertJsonPath('data.employee.has_picture', true);
        $p = Employee::withoutGlobalScopes()->find($w['eEmp']->id)->avatar_path;
        expect($p)->toStartWith('avatars/'.$w['c']->id.'/')->toEndWith('.jpg');
        Storage::disk('local')->assertExists($p);
        if ($first) {
            Storage::disk('local')->assertMissing($first);
        }
        $first = $p;
    }
    $r = lc_call($this, $w['empT'], 'GET', '/api/v1/me/profile/picture')->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('image/jpeg');
    [$wd, $ht] = getimagesizefromstring($r->baseResponse->getFile()->getContent());
    expect($wd)->toBe(512)->and($ht)->toBe(512);
    // HR (employees.view) may view; another employee may not; other tenant may not
    lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$w['eEmp']->id}/picture")->assertOk();
    [$peer, $peerT] = lc_user($w['c'], 'employee', ['email' => 'peer@a.test']);
    lc_call($this, $peerT, 'GET', "/api/v1/employees/{$w['eEmp']->id}/picture")->assertStatus(404);
    $b = lc_world('b.test');
    lc_call($this, $b['hrT'], 'GET', "/api/v1/employees/{$w['eEmp']->id}/picture")->assertStatus(404);
    lc_call($this, $w['empT'], 'DELETE', '/api/v1/me/profile/picture')->assertOk()->assertJsonPath('data.employee.has_picture', false);
    Storage::disk('local')->assertMissing($first);
    expect(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->avatar_path)->toBeNull();
    lc_call($this, $w['empT'], 'GET', '/api/v1/me/profile/picture')->assertStatus(404);
});

test('picture validation: non-raster, disguised, oversized, tiny, huge dimension and corrupt files are rejected and nothing is stored', function () {
    $w = lc_world();
    $bad = [];
    $svg = tempnam(sys_get_temp_dir(), 's');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $bad['svg'] = new UploadedFile($svg, 'a.svg', 'image/svg+xml', null, true);
    $php = tempnam(sys_get_temp_dir(), 'p');
    file_put_contents($php, '<?php echo 1;');
    $bad['php-as-jpg'] = new UploadedFile($php, 'a.jpg', 'image/jpeg', null, true);
    $gif = tempnam(sys_get_temp_dir(), 'g');
    $im = imagecreatetruecolor(100, 100);
    imagegif($im, $gif);
    $bad['gif'] = new UploadedFile($gif, 'a.gif', 'image/gif', null, true);
    $trunc = tempnam(sys_get_temp_dir(), 't');
    $j = lc_img('jpg', 200, 200);
    file_put_contents($trunc, substr(file_get_contents($j->getPathname()), 0, 60));
    $bad['corrupt'] = new UploadedFile($trunc, 'a.jpg', 'image/jpeg', null, true);
    $bad['tiny'] = lc_img('png', 20, 20);
    $bad['huge-dim'] = lc_img('png', 4100, 100);
    $big = tempnam(sys_get_temp_dir(), 'b');
    file_put_contents($big, str_repeat('A', 3 * 1024 * 1024));
    $bad['3mb-garbage'] = new UploadedFile($big, 'a.jpg', 'image/jpeg', null, true);
    foreach ($bad as $label => $file) {
        $r = lc_call($this, $w['empT'], 'POST', '/api/v1/me/profile/picture', ['picture' => $file]);
        expect($r->status())->toBeIn([413, 422], $label);
    }
    expect(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->avatar_path)->toBeNull()->and(Storage::disk('local')->allFiles())->toBe([]);
    lc_call($this, $w['empT'], 'POST', '/api/v1/me/profile/picture', [])->assertStatus(422);
});

test('picture client file name never reaches the path', function () {
    $w = lc_world();
    $f = lc_img('jpg', 200, 200, '../../etc/passwd');
    lc_call($this, $w['empT'], 'POST', '/api/v1/me/profile/picture', ['picture' => $f])->assertOk();
    $p = Employee::withoutGlobalScopes()->find($w['eEmp']->id)->avatar_path;
    expect($p)->toMatch('#^avatars/\d+/[0-9a-f\-]{36}\.jpg$#');
});
