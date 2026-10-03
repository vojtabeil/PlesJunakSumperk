import { useRef } from 'preact/hooks';
import type { Store } from '../store';

/** Step 1: the e-mail that identifies the reservation. */
export function EmailForm({ store }: { store: Store }) {
  const input = useRef<HTMLInputElement>(null);

  const submit = (event: SubmitEvent) => {
    event.preventDefault();
    const field = input.current;
    if (!field || field.value.trim() === '' || !field.checkValidity()) {
      store.show('Zadejte platný e-mail.', true);
      field?.focus();
      return;
    }
    void store.start(field.value.trim());
  };

  return (
    <form class="email-form" noValidate onSubmit={submit}>
      <label for="email">E-mail</label>
      <div class="inline-field">
        <input
          ref={input}
          type="email"
          id="email"
          name="email"
          autoComplete="email"
          required
          maxLength={255}
          placeholder="jmeno@example.cz"
        />
        <button type="submit" class="button" disabled={store.busy.value}>
          Pokračovat
        </button>
      </div>
    </form>
  );
}
