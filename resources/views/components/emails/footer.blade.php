{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? 'Coolify' }}

{{ Illuminate\Mail\Markdown::parse('[Report an issue](https://github.com/Terrence721/coolify-full/issues)') }}
