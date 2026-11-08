<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #1a1a2e;
            line-height: 1.4;
        }

        .header {
            padding: 16px 24px;
            border-bottom: 2px solid #2d3436;
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 18px;
            font-weight: 700;
            color: #2d3436;
            margin-bottom: 4px;
        }

        .header .meta {
            font-size: 8px;
            color: #636e72;
        }

        .filters {
            padding: 8px 24px;
            margin-bottom: 12px;
        }

        .filters .label {
            font-weight: 600;
            color: #636e72;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filters .value {
            display: inline;
            background: #f1f3f5;
            padding: 2px 6px;
            border-radius: 3px;
            margin: 0 4px;
            font-size: 8px;
        }

        .content {
            padding: 0 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }

        thead th {
            background: #2d3436;
            color: #ffffff;
            padding: 6px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
            word-wrap: break-word;
        }

        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 8px 24px;
            border-top: 1px solid #dee2e6;
            font-size: 7px;
            color: #adb5bd;
            text-align: center;
        }

        .row-count {
            padding: 4px 24px;
            font-size: 8px;
            color: #636e72;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="meta">Generated: {{ $generatedAt }}</div>
    </div>

    @if(count($appliedFilters) > 0)
        <div class="filters">
            <span class="label">Filters applied:</span>
            @foreach($appliedFilters as $key => $value)
                <span class="value">{{ str_replace('_', ' ', ucfirst($key)) }}: {{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</span>
            @endforeach
        </div>
    @endif

    <div class="row-count">
        Total rows: {{ number_format(count($rows)) }}
    </div>

    <div class="content">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    @foreach($columns as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $index => $row)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        @foreach($columns as $key => $label)
                            <td>{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}" style="text-align: center; padding: 20px; color: #adb5bd;">
                            No records found matching the applied filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="footer">
        {{ config('app.name') }} &mdash; {{ $title }} &mdash; Page {PAGE_NUM} of {PAGE_COUNT}
    </div>
</body>
</html>
