@if(session('status'))<div class="amd-account-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="amd-errors" role="alert"><strong>Controlla i dati inseriti.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
