import { AddGameForm } from './components/AddGameForm'
import { ErrorAlert } from './components/ErrorAlert'
import { GameList } from './components/GameList'
import { LimitBanner } from './components/LimitBanner'
import { StatusRegion } from './components/StatusRegion'
import { useGameLibrary } from './hooks/useGameLibrary'
import officeGames from './assets/office-games.png'

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

      <header className="border-b border-line bg-surface py-4">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4">
          <p className="text-xs font-bold tracking-[0.2em] uppercase">
            Essense of Australia
          </p>
          <p className="text-[0.7rem] tracking-[0.15em] text-accent uppercase">
            Code Challenge
          </p>
        </div>
      </header>

      <main id="main" className="mx-auto max-w-4xl space-y-8 px-4 py-8">
        <div className="grid items-center gap-6 md:grid-cols-[2fr_3fr] md:gap-10">
          <img
            src={officeGames}
            alt="Retro gaming setup with lava lamps and joysticks"
            width={727}
            height={1000}
            fetchPriority="high"
            className="aspect-[4/3] w-full rounded-sm object-cover md:aspect-auto md:h-auto"
          />
          <div className="space-y-3">
            <h1 className="text-3xl font-normal">
              Vote for Your Favorite Game
            </h1>
            <p className="text-sm leading-relaxed text-muted">
              We want your input to help us build the best office game library.
              Once per day, you can either suggest a game or vote for one. As
              the list grows, keep checking back every day to add or vote.
            </p>
          </div>
        </div>

        <div className="mx-auto max-w-2xl space-y-8">
          {library.me && <LimitBanner me={library.me} />}

          <ErrorAlert
            message={library.actionError}
            onDismiss={library.dismissError}
          />

          <AddGameForm library={library} />
          <GameList library={library} />
        </div>
      </main>

      <StatusRegion notice={library.notice} />
    </div>
  )
}
