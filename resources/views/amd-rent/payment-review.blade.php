@if(auth()->user()->hasRole('admin'))
<details class="amr-disclosure">
    <summary>Verifica admin del pagamento</summary>
    <p>Controlla il pagamento e gli eventuali rimborsi nel pannello Stripe. Questa operazione registra la tua decisione; non esegue addebiti o rimborsi.</p>
    <form class="amr-form" method="post" action="{{ route('amd-rent.bookings.review', $booking) }}">
        @csrf @method('PUT')
        <input type="hidden" name="refund_snapshot" value="{{ $booking->refunded_cents }}">
        <label for="review-action-{{ $booking->id }}">Esito della verifica</label>
        <select class="app-field" name="action" id="review-action-{{ $booking->id }}" required>
            <option value="">Seleziona</option>
            <option value="keep">Mantieni la prenotazione e aggiorna il saldo</option>
            <option value="cancel">Annulla dopo il rimborso completo</option>
        </select>
        <label for="review-note-{{ $booking->id }}">Motivo e accordo verificato con il cliente</label>
        <textarea class="app-field" name="note" id="review-note-{{ $booking->id }}" minlength="10" maxlength="1000" required rows="3"></textarea>
        <label class="amr-check"><input type="checkbox" name="verified_in_stripe" value="1" required><span>Ho verificato lo stato nel pannello Stripe e concordato l’esito con il cliente.</span></label>
        <button class="amr-button">Registra verifica</button>
    </form>
    <p>Una prenotazione già annullata non può essere riattivata. Per annullare, il rimborso completo deve risultare ricevuto da Stripe e il noleggio non deve essere iniziato.</p>
</details>
@endif
