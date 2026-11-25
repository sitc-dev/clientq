<dialog id="deleteConfirmation" aria-labelledby="deleteConfirmationTitle"
    aria-describedby="deleteConfirmationWarning"
    data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
    class="w-[calc(100%_-_2rem)] max-w-lg rounded-2xl p-6 text-slate-900 shadow-xl backdrop:bg-slate-900/60">
    <h2 id="deleteConfirmationTitle" class="text-xl font-bold text-red-700"></h2>
    <p id="deleteConfirmationTarget" class="mt-3 break-words font-semibold"></p>
    <p id="deleteConfirmationWarning" class="mt-3 text-slate-700"></p>
    <form id="deleteConfirmationForm" class="mt-5 space-y-4">
        <label id="deleteConfirmationLabel" for="deleteConfirmationInput"
            class="block break-words text-sm font-semibold"></label>
        <input id="deleteConfirmationInput" required autocomplete="off" autofocus
            class="w-full rounded-lg border px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-700">
        <p id="deleteConfirmationError" class="hidden text-sm text-red-700" role="alert"></p>
        <div class="flex justify-end gap-3">
            <button id="deleteConfirmationCancel" type="button"
                class="rounded-lg border px-4 py-2 font-semibold hover:bg-slate-50 disabled:opacity-50">
                Cancel
            </button>
            <button id="deleteConfirmationSubmit" type="submit"
                class="rounded-lg bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-800 disabled:opacity-50">
                Confirm change
            </button>
        </div>
    </form>
</dialog>
<script src="/assets/delete-confirmation.js" defer></script>
