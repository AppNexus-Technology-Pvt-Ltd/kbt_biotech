<dialog class="dialog" id="confirm-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-message">
    <form method="dialog">
        <h3 id="confirm-title">Are you sure?</h3>
        <p id="confirm-message"></p>
        <div data-confirm-extra></div>
        <div class="dialog__actions">
            <button type="submit" value="cancel" class="btn btn--outline btn--sm" data-confirm-cancel>Cancel</button>
            <button type="submit" value="ok" class="btn btn--primary btn--sm" data-confirm-ok>Confirm</button>
        </div>
    </form>
</dialog>
<div class="toasts" id="toasts" aria-live="polite"></div>
