import { AddGameForm } from './components/AddGameForm'
import { ErrorAlert } from './components/ErrorAlert'
import { GameList } from './components/GameList'
import { LimitBanner } from './components/LimitBanner'
import { StatusRegion } from './components/StatusRegion'
import { useGameLibrary } from './hooks/useGameLibrary'

export default function App() {
  const library = useGameLibrary()

  return (
    <div className="min-h-screen bg-page font-sans text-ink">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-10 focus:rounded-sm focus:bg-surface focus:p-3 focus:outline-2 focus:outline-ink"
      >
        Skip to main content
      </a>

      <header className="border-b border-line bg-surface px-4 py-4">
        <div className="mx-auto max-w-2xl">
          <p className="text-xs font-bold tracking-[0.2em] uppercase">
            Essense of Australia
          </p>
          <p className="mt-0.5 text-[0.7rem] tracking-[0.15em] text-accent uppercase">
            Code Challenge
          </p>
        </div>
      </header>

      <main id="main" className="mx-auto max-w-2xl space-y-8 px-4 py-8">
        <div className="space-y-3">
          <h1 className="text-3xl font-extralight">Game Voting</h1>
          <p className="text-sm leading-relaxed text-muted">
            Suggest games for the office library and vote for the ones you want
            most.
          </p>
        </div>

        {library.me && <LimitBanner me={library.me} />}

        <ErrorAlert
          message={library.actionError}
          onDismiss={library.dismissError}
        />

        <AddGameForm library={library} />
        <GameList library={library} />
      </main>

      <StatusRegion notice={library.notice} />
    </div>
  )
}
