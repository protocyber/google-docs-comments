<?php

// Builds tests/Browser/out/{rich.html,runs.json,alpine.js} for preview.spec.ts.
// Run inside the app container: make gd-php A="tests/Browser/build-harness.php"

use App\Services\GoogleDocsPreview;
use App\Services\Preview\TextIndex;
use Tests\Support\DocFixture;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = __DIR__.'/out';
@mkdir($out, 0775, true);

$doc = DocFixture::rich()->fetched();
$preview = GoogleDocsPreview::build($doc);

$runs = [];
foreach (TextIndex::runs($doc->content()) as [$s, $e, $text]) {
    $runs[] = [$s, $e, $text];
}
file_put_contents("$out/runs.json", json_encode($runs, JSON_UNESCAPED_UNICODE));

file_put_contents("$out/rich.html", view()->file(__DIR__.'/harness.blade.php', [
    'css' => file_get_contents(resource_path('css/gd-preview.css')),
    'js' => file_get_contents(resource_path('js/gd-preview.js')),
    'html' => $preview->html,
    'threads' => $preview->threads,
])->render());

copy(base_path('node_modules/alpinejs/dist/cdn.min.js'), "$out/alpine.js");
echo "harness written to $out\n";
