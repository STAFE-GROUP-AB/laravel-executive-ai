{{--
    Executive Brief — HTML mail part (S1-03, blueprint §1.3, §4.2, §5.5).

    Rules this template follows, each guarded by tests/Feature/Briefs/Mail/:
    - Every style="" value is one BriefMailStyles::all() entry (S0-24 mail-safe subset). No <style>,
      no classes, no CSS variables, no shadows, no web fonts, no images, no scripts, no remote assets.
    - Table layout, 600px, role="presentation" on every table, light scheme pinned via meta so
      Apple Mail and HEY do not auto-invert. Headings in order h1 > h2 > h3.
    - Blade escapes everything with the double-brace echo; the raw echo is never used in this file
      (threat model T-22). Brief prose is plain text by schema and is printed as text.
    - Every item prints its source references or its uncertainty marker; both when both exist.
      due_at appears only when the document has it (§4.2: no invented deadlines).
    - The data is the BriefViewModel built from the parity payload — nothing else is in scope.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<title>{{ $subject }}</title>
</head>
<body style="{{ $styles['body'] }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $styles['table'] }}">
<tr>
<td align="center" style="{{ $styles['canvas-cell'] }}">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="{{ $styles['container'] }}">
<tr>
<td style="{{ $styles['card'] }}">
<p style="{{ $styles['label'] }}">{{ __('brief.brand') }} · {{ $version_label }}</p>
<h1 style="{{ $styles['h1'] }}">{{ $subject }}</h1>
<p style="{{ $styles['meta'] }}">{{ __('brief.generated_at') }}: {{ $generated_at_label }}</p>
<p style="{{ $styles['meta'] }}">{{ __('brief.period') }}: {{ $period_label }}</p>
<hr style="{{ $styles['divider'] }}">
@foreach($summary_paragraphs as $paragraph)
<p style="{{ $styles['p'] }}">{{ $paragraph }}</p>
@endforeach
</td>
</tr>
<tr>
<td style="{{ $styles['gap'] }}">&nbsp;</td>
</tr>
@if($items === [])
<tr>
<td style="{{ $styles['card'] }}">
<h2 style="{{ $styles['h2-section'] }}">{{ __('brief.nothing_new_title') }}</h2>
<p style="{{ $styles['secondary'] }}">{{ __('brief.nothing_new_body') }}</p>
</td>
</tr>
@else
<tr>
<td style="{{ $styles['section-heading-cell'] }}">
<h2 style="{{ $styles['h2-section'] }}">{{ __('brief.items') }} ({{ count($items) }})</h2>
</td>
</tr>
@foreach($items as $item)
<tr>
<td style="{{ $styles['item-cell'] }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $styles['table'] }}">
<tr>
<td style="{{ $styles['card'] }}">
<p style="{{ $styles['badge-row'] }}"><span style="{{ $styles['badge-accent'] }}">{{ $item['kind_label'] }}</span> <span style="{{ $styles[$item['priority_badge']] }}">{{ $item['priority_label'] }}</span></p>
<h3 style="{{ $styles['h3'] }}">{{ $item['number'] }}. {{ $item['title'] }}</h3>
@foreach($item['body_paragraphs'] as $paragraph)
<p style="{{ $styles['p'] }}">{{ $paragraph }}</p>
@endforeach
@if($item['due_at_label'] !== null)
<p style="{{ $styles['meta'] }}"><strong>{{ __('brief.due') }}:</strong> {{ $item['due_at_label'] }}</p>
@endif
@if($item['sources'] !== [])
<p style="{{ $styles['label'] }}">{{ trans_choice('brief.sources', count($item['sources'])) }}</p>
<ul style="{{ $styles['source-list'] }}">
@foreach($item['sources'] as $source)
<li style="{{ $styles['source-item'] }}">{{ $source['type_label'] }}@if($source['account_label'] !== null) · {{ $source['account_label'] }}@endif @if($source['title'] !== null)· {{ $source['title'] }} @endif· @if($source['href'] !== null)<a href="{{ $source['href'] }}" style="{{ $styles['link'] }}">{{ $source['uri'] }}</a>@else<span style="{{ $styles['code'] }}">{{ $source['uri'] }}</span>@endif</li>
@endforeach
</ul>
@endif
@if($item['uncertainty'] !== null)
<p style="{{ $styles['callout-warning'] }}"><strong>{{ __('brief.uncertainty') }}</strong> ({{ $item['uncertainty']['confidence_label'] }}): {{ $item['uncertainty']['reason'] }}</p>
@endif
@foreach($item['approvals'] as $approval)
<p style="{{ $styles['meta'] }}"><a href="{{ $approval['href'] }}" style="{{ $styles['link'] }}">{{ __('brief.approval_required') }}</a></p>
@endforeach
</td>
</tr>
</table>
</td>
</tr>
@endforeach
@endif
<tr>
<td style="{{ $styles['cta-cell'] }}"><a href="{{ $open_url }}" style="{{ $styles['button'] }}">{{ __('brief.open_in_app') }}</a></td>
</tr>
<tr>
<td style="{{ $styles['footer-cell'] }}">
<p style="{{ $styles['footer'] }}">{{ __('brief.privacy_mode') }}: {{ $privacy_mode_label }} ({{ $privacy_mode }})</p>
<p style="{{ $styles['footer'] }}">{{ __('brief.brief_id') }} {{ $brief_id }} · {{ $version_label }} · {{ $content_hash_short }}</p>
<p style="{{ $styles['footer'] }}">{{ __('brief.footer_note', ['app' => $app_name]) }}</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
