import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import type { GameLibrary } from '../hooks/useGameLibrary'
import { validateTitle } from '../lib/title'
import { LIMIT_NOTE_ID } from './LimitBanner'
import { primaryButton, textInput } from './styles'

interface Props {
  library: GameLibrary
}

export function AddGameForm({ library }: Props) {
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const limited = library.me?.actionAvailable === false
  const blocked = limited || library.adding

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (library.adding) return

    const problem = validateTitle(
      value,
      library.games.map((game) => game.title)
    )

    if (problem) {
      setError(problem)
      inputRef.current?.focus()
      return
    }

    setError(null)
    const result = await library.add(value)

    if (result.ok) {
      setValue('')
    } else if (result.field) {
      setError(result.message)
      inputRef.current?.focus()
    }
  }

  const describedBy = [
    'title-hint',
    error ? 'title-error' : null,
    limited ? LIMIT_NOTE_ID : null,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-2">
      <label htmlFor="game-title" className="block text-sm font-semibold">
        Suggest a game
      </label>
      <p id="title-hint" className="text-sm text-muted">
        Up to 100 characters. Titles that are already on the list are rejected.
      </p>
      <div className="flex flex-col gap-2 sm:flex-row">
        <input
          ref={inputRef}
          id="game-title"
          name="title"
          type="text"
          autoComplete="off"
          value={value}
          onChange={(event) => {
            setValue(event.target.value)
            if (error) setError(null)
          }}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={`${textInput} sm:flex-1 ${error ? '' : 'border-control'}`}
        />
        <button
          type="submit"
          aria-disabled={blocked}
          onClick={(event) => {
            if (library.adding) event.preventDefault()
          }}
          className={primaryButton}
        >
          {library.adding ? 'Adding…' : 'Add game'}
        </button>
      </div>
      {/* Live, because focusing an input that already has focus (Enter to
          submit) does not make screen readers re-read its description. */}
      <p
        id="title-error"
        aria-live="assertive"
        className="min-h-5 text-sm font-medium text-danger"
      >
        {error}
      </p>
    </form>
  )
}
