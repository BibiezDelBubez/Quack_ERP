<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Motore generico di verifica delle transizioni di stato.
 *
 * Non conosce nessuna entità specifica: riceve la mappa delle transizioni
 * ammesse (definita altrove, una per entità — vedi includes/transizioni_stati.php)
 * e la usa per validare. Un'unica implementazione per tutta l'app: qualsiasi
 * entità con un ciclo di vita a stati deve passare da qui, non reinventare il
 * controllo altrove (vedi docs/plans/PIANO - Motore Unificato di Transizioni
 * di Stato.md).
 *
 * Due istanze create per due entità diverse sono completamente indipendenti:
 * non esiste nessun vocabolario di stati condiviso fra entità, ogni mappa è
 * un array a sé (anche se per coincidenza usa le stesse parole di un'altra
 * entità, es. 'bozza'/'annullato' — sono trattate come stringhe opache).
 */
final class TransitionGuard
{
    /** @param array<string,string[]> $mappaTransizioni stato_attuale => [stati_successivi_ammessi] */
    public function __construct(
        private readonly array $mappaTransizioni,
        private readonly string $nomeEntita
    ) {
    }

    /**
     * Verifica che si possa passare da $statoAttuale a $statoRichiesto.
     * Lancia RuntimeException con un messaggio comprensibile se non ammessa.
     */
    public function assicura(string $statoAttuale, string $statoRichiesto): void
    {
        if (!$this->puoTransire($statoAttuale, $statoRichiesto)) {
            throw new \RuntimeException(sprintf(
                "Operazione non consentita su %s: transizione da '%s' a '%s' non ammessa.",
                $this->nomeEntita,
                $statoAttuale,
                $statoRichiesto
            ));
        }
    }

    /** Come assicura(), ma con messaggio d'errore personalizzato. */
    public function assicuraConMessaggio(string $statoAttuale, string $statoRichiesto, string $messaggioErrore): void
    {
        if (!$this->puoTransire($statoAttuale, $statoRichiesto)) {
            throw new \RuntimeException($messaggioErrore);
        }
    }

    public function puoTransire(string $statoAttuale, string $statoRichiesto): bool
    {
        return in_array($statoRichiesto, $this->mappaTransizioni[$statoAttuale] ?? [], true);
    }

    /**
     * Stati raggiungibili da $statoAttuale. Pensato anche per la view, così
     * da poter chiedere "quali azioni sono valide da qui" invece di duplicare
     * la lista con un in_array scollegato dalla vera regola (vedi Tappa 8 del
     * PIANO, app/views/sales/quotes/_form.php).
     *
     * @return string[]
     */
    public function transizioniAmmesse(string $statoAttuale): array
    {
        return $this->mappaTransizioni[$statoAttuale] ?? [];
    }
}
