@if ($errors->any())
    <div class="validation-summary" role="alert" tabindex="-1">
        <strong>A quick thing before we continue.</strong>
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
