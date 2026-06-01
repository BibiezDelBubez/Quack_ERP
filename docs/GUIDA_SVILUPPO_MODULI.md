# Guida allo sviluppo di nuovi moduli — Quack ERP

> Versione: maggio 2026. Aggiornare questo documento ad ogni modifica strutturale.

---

## Indice

1. [Cos'è l'architettura attuale — spiegazione semplice](#1-cosè-larchitettura-attuale--spiegazione-semplice)
2. [Mappa dei file](#2-mappa-dei-file)
3. [Come funziona il routing](#3-come-funziona-il-routing)
4. [Cos'è un controller e perché esiste](#4-cosè-un-controller-e-perché-esiste)
5. [Aggiungere un nuovo modulo — procedura passo-passo](#5-aggiungere-un-nuovo-modulo--procedura-passo-passo)
6. [Template pronti da copiare](#6-template-pronti-da-copiare)
7. [Funzioni helper disponibili](#7-funzioni-helper-disponibili)
8. [Come usare il database](#8-come-usare-il-database)
9. [Sistema dei permessi](#9-sistema-dei-permessi)
10. [Componenti CSS disponibili](#10-componenti-css-disponibili)
11. [Report audit — problemi, incoerenze, migliorie](#11-report-audit--problemi-incoerenze-migliorie)
12. [Checklist per un nuovo modulo](#12-checklist-per-un-nuovo-modulo)

---

## 1. Cos'è l'architettura attuale — spiegazione semplice

L'app è scritta in PHP puro, senza framework. L'architettura segue il pattern **MVC** (Model-View-Controller), che divide il codice in tre livelli con responsabilità ben separate.

Per capire l'MVC immagina di progettare un gioco di battaglia navale.

I dati veri (dove sono le navi, di chi sono, di quale tipo) sono numeri contenuti in una matrice. Questo è il **Model** — i dati grezzi.  
La parte grafica, con le navi e la griglia, è una rappresentazione visiva di quei numeri. Questa è la **View** — solo presentazione.  
La parte che collega Model e View, decide cosa mostrare e come reagire ai click, è il **Controller**.

**Perché questa separazione è utile?**

Se metti tutto insieme (query + validazione + HTML), dopo sei mesi non capisci più dove cercare un bug. Con la separazione:

- Un problema di dati? Guardi `anagrafiche.php` o la query nel controller.
- Un problema di comportamento (es. il redirect va nel posto sbagliato)? Guardi il Controller.
- Un problema visivo (es. la tabella non si allinea)? Guardi il file `_list.php`.

**Lo stato attuale:** l'app usa il pattern MVC in modo coerente. Tutti i moduli hanno un controller in `app/Controllers/` e le viste in `app/views/`. Non esistono più file "legacy" che mescolano logica e HTML.

---

## 2. Mappa dei file

```
Quack/                              ← root dell'app (htdocs/Quack)
│
├── index.php                       ← FRONT CONTROLLER: ogni richiesta passa da qui
├── 404.php                         ← pagina di errore 404
│
├── app/
│   ├── bootstrap.php               ← carica config + db + functions + Router
│   ├── routes.php                  ← REGISTRO di tutte le URL ← si modifica qui
│   ├── Core/
│   │   └── Router.php              ← classe Router (non toccare)
│   ├── Controllers/                ← ★ LOGICA HTTP di ogni modulo
│   │   ├── BaseController.php      ← classe base (pdo, render, page)
│   │   ├── DashboardController.php
│   │   ├── CustomersController.php
│   │   ├── SuppliersController.php
│   │   ├── ProductsController.php
│   │   ├── OrdersController.php
│   │   ├── OffersController.php
│   │   ├── ProfileController.php
│   │   ├── UsersController.php     ← gestione utenti (URL: /utenti)
│   │   ├── SettingsController.php
│   │   ├── EcommerceController.php
│   │   └── ThemeController.php
│   └── views/                      ← ★ HTML di ogni modulo (solo output)
│       ├── auth/
│       │   ├── login.php           ← pagina login (sceglie skin A/B/C/D)
│       │   ├── _form.php           ← partial: form condiviso da tutte le skin
│       │   └── logout.php          ← action logout
│       ├── shared/
│       │   └── _coming_soon.php    ← partial riutilizzabile per stub
│       ├── dashboard/
│       │   └── _index.php
│       ├── customers/
│       │   ├── _list.php
│       │   └── _form.php
│       ├── suppliers/
│       │   ├── _list.php
│       │   └── _form.php
│       ├── products/
│       │   └── _list.php
│       ├── orders/
│       │   └── _list.php
│       ├── offers/
│       │   └── _list.php
│       ├── utenti/
│       │   ├── _list.php
│       │   └── _form.php
│       └── profile/
│           └── _index.php
│
├── includes/
│   ├── config.php                  ← costanti globali (BASE_URL, APP_ROOT_DIR…)
│   ├── db.php                      ← connessione PDO SQLite → $pdo globale
│   ├── functions.php               ← helper (url, e, redirect, can, csrf…)
│   ├── anagrafiche.php             ← Model: funzioni DB per clienti e fornitori
│   ├── header.php                  ← layout: apre <html>, sidebar, topbar
│   ├── footer.php                  ← layout: chiude </html>
│   └── sidebar.php                 ← navigazione laterale
│
├── assets/
│   ├── css/
│   │   ├── app.css                 ← CSS principale (classi qk-*)
│   │   ├── theme.css               ← variabili tema chiaro/scuro
│   │   └── login.css               ← solo schermata login
│   ├── js/
│   │   ├── app.js                  ← JS principale
│   │   ├── bootstrap.bundle.js
│   │   ├── p5.min.js               ← libreria p5.js (animazioni login)
│   │   ├── login-skin-a.js         ← Skin A: "Increspature"
│   │   ├── login-skin-b.js         ← Skin B: "Onde orizzontali"
│   │   ├── login-skin-c.js         ← Skin C: "Onde piatte piene"
│   │   ├── login-skin-d.js         ← Skin D: "Mattonelle fondale distorte"
│   │   └── login-interactions.js   ← ripple click sul bottone login
│   └── fontawesome/                ← icone FA 6 (offline)
│
├── db/
│   ├── database.sqlite             ← il database
│   ├── init.sql                    ← schema + seed (eseguito auto al primo avvio)
│   └── crea_db.php                 ← utility per ricreare il DB da zero
│
│   ── CARTELLE MODULO (solo viste a radice, NIENTE logica) ──────────────────
│
├── customers/                      ← ⚠ contiene _list.php e _form.php duplicati
├── suppliers/                      ← ⚠ contiene _list.php e _form.php duplicati
├── products/                       ← cartella vuota (stub)
├── offers/                         ← cartella vuota (stub)
├── orders/                         ← cartella vuota (stub)
├── dashboard/                      ← cartella vuota
├── profile/                        ← cartella vuota
└── utenti/                         ← cartella vuota
```

> ⚠️ Le cartelle `customers/` e `suppliers/` contengono ancora copie vecchie di `_list.php` e `_form.php`. I controller usano le versioni in `app/views/`. Le copie a radice sono **dead code** e vanno eliminate (vedi sezione 11).

---

## 3. Come funziona il routing

**Regola fondamentale: nessun file PHP è raggiungibile direttamente via URL.**

Ogni richiesta (es. `http://localhost/Quack/products`) arriva sempre a `index.php`, che:

1. legge l'URL richiesto (`/products`)
2. lo cerca in `app/routes.php`
3. istanzia il controller giusto e chiama il metodo corretto

Questo significa che **creare un file non basta** — devi anche registrare la route in `routes.php`.

### I tipi di route

| `type`       | Cosa fa                                            | Dove cerca                           |
| ------------ | -------------------------------------------------- | ------------------------------------ |
| `controller` | Istanzia il Controller, chiama il metodo           | `app/Controllers/NomeController.php` |
| `view`       | Include direttamente un file di vista              | `app/views/target`                   |
| `redirect`   | Reindirizza a un'altra URL                         | —                                    |
| `legacy`     | Carica un file dalla root (usato solo per 404.php) | `Quack/target`                       |

**Regola pratica:** usa sempre `controller` per i nuovi moduli. Gli altri tipi sono usati solo per login/logout/404.

### Esempio di route

```php
// app/routes.php
'/products'        => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'index',  'auth' => true],
'/products/create' => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'create', 'auth' => true],
'/products/edit'   => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'edit',   'auth' => true],
'/products/delete' => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'delete', 'auth' => true],
```

Quando arriva `/products/create`, `index.php`:

1. carica `app/Controllers/BaseController.php`
2. carica `app/Controllers/ProductsController.php`
3. crea `new ProductsController($pdo)`
4. chiama `$ctrl->create()`

---

## 4. Cos'è un controller e perché esiste

Un **controller** fa da coordinatore tra input HTTP, logica di business e vista HTML.

**Cosa fa concretamente un metodo di controller:**

```
1. Legge l'input (GET, POST)
2. Controlla i permessi (can('manage_catalog'))
3. Valida i dati
4. Chiama le query / funzioni del model
5. Se errori → mostra di nuovo il form con i messaggi
6. Se tutto ok → redirect con flash message
7. Se GET pura → carica i dati e mostra la vista
```

**Cosa NON fa un controller:** non contiene HTML. Mai.  
**Cosa NON fa una vista (`_list.php`, `_form.php`):** non fa query, non valida, non fa redirect. Solo `<?= e($var) ?>` e struttura HTML.

### Come includere le viste — il pattern standard

Il metodo standard (usato da `CustomersController`, `SuppliersController` e tutti i controller principali) è l'**inline require**:

```php
public function index(): void
{
    // ... logica ...

    $page_title   = 'Prodotti';
    $breadcrumbs  = [['label' => 'Prodotti']];
    $page_actions = '<a href="' . url('products/create') . '" class="btn-qk btn-qk-primary">...</a>';

    require INCLUDES_DIR . '/header.php';
    require APP_ROOT_DIR . '/app/views/products/_list.php';
    require INCLUDES_DIR . '/footer.php';
}
```

Le variabili definite nel metodo (`$rows`, `$total`, `$search`…) sono automaticamente disponibili nella vista inclusa — PHP condivide lo scope del metodo con il file `require`'ato.

`BaseController` offre anche il metodo helper `$this->page()` che fa la stessa cosa in modo compatto, passando le variabili tramite un array:

```php
$this->page(APP_ROOT_DIR . '/app/views/products/_list.php', [
    'page_title'  => 'Prodotti',
    'breadcrumbs' => [['label' => 'Prodotti']],
    'rows'        => $rows,
    'total'       => $total,
]);
```

**Usa l'inline require** per i nuovi moduli — è più diretto e coerente con i controller esistenti.

---

## 5. Aggiungere un nuovo modulo — procedura passo-passo

Esempio: modulo **Prodotti** con lista, creazione, modifica e cancellazione.

### Passo 1 — Crea i file di vista

```
app/views/products/_list.php   ← HTML della tabella
app/views/products/_form.php   ← HTML del form (create + edit)
```

### Passo 2 — Crea il controller

```
app/Controllers/ProductsController.php
```

Il controller deve estendere `BaseController` e usare `$this->pdo`.

### Passo 3 — Registra le route in `app/routes.php`

Aggiungi nel blocco principale, prima delle "Legacy URL compatibility":

```php
'/products'        => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'index',  'auth' => true],
'/products/create' => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'create', 'auth' => true],
'/products/edit'   => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'edit',   'auth' => true],
'/products/delete' => ['type' => 'controller', 'controller' => 'ProductsController', 'action' => 'delete', 'auth' => true],
```

> ⚠️ L'URL non deve avere `.php`. Usa `/products/create`, non `/products/create.php`.

Se vuoi anche la compatibilità con eventuali vecchi link `.php`, aggiungi nel blocco "Legacy URL compatibility":

```php
'/products/index.php'  => ['type' => 'redirect', 'target' => '/products'],
'/products/create.php' => ['type' => 'redirect', 'target' => '/products/create'],
```

### Passo 4 — Aggiungi il link nella sidebar

Apri `includes/sidebar.php` e inserisci la voce nel gruppo appropriato:

```php
<li class="qk-nav-item">
  <a href="<?= url('products') ?>" title="Prodotti"
     class="<?= nav_active('/products') ?>">
    <i class="fa-solid fa-tags"></i>
    <span class="qk-nav-label">Prodotti</span>
  </a>
</li>
```

### Passo 5 — Scrivi le viste HTML

`app/views/products/_list.php` — solo HTML, usa le variabili definite nel metodo `index()`.  
`app/views/products/_form.php` — solo HTML, usa `$values`, `$errors`, `$mode`, `$entity_id`.

---

## 6. Template pronti da copiare

### Controller completo

```php
<?php
// app/Controllers/ProductsController.php
declare(strict_types=1);

class ProductsController extends BaseController
{
    /* ── Lista ── */
    public function index(): void
    {
        if (!can('manage_catalog')) {
            redirect_with('dashboard', 'danger', 'Accesso non autorizzato.');
        }

        $search = get_param('q');

        $stmt = $this->pdo->prepare(
            'SELECT * FROM prodotti WHERE nome LIKE :q ORDER BY nome'
        );
        $stmt->execute([':q' => '%' . $search . '%']);
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = count($rows);

        $page_title    = 'Prodotti';
        $page_subtitle = 'Catalogo prodotti';
        $breadcrumbs   = [['label' => 'Prodotti']];
        $page_actions  = '<a href="' . url('products/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo prodotto
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/products/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Creazione ── */
    public function create(): void
    {
        if (!can('manage_catalog')) {
            redirect_with('dashboard', 'danger', 'Accesso non autorizzato.');
        }

        $mode      = 'create';
        $entity_id = null;
        $errors    = [];
        $values    = ['nome' => '', 'tipo' => 'semplice'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values['nome'] = trim(post('nome'));
                $values['tipo'] = post('tipo', 'semplice');

                if ($values['nome'] === '') $errors['nome'] = 'Il nome è obbligatorio.';

                if (empty($errors)) {
                    $this->pdo->prepare(
                        'INSERT INTO prodotti (nome, tipo) VALUES (:n, :t)'
                    )->execute([':n' => $values['nome'], ':t' => $values['tipo']]);
                    redirect_with('products', 'success', 'Prodotto creato correttamente.');
                }
            }
        }

        $page_title  = 'Nuovo prodotto';
        $breadcrumbs = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => 'Nuovo'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/products/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Modifica ── */
    public function edit(): void
    {
        if (!can('manage_catalog')) {
            redirect_with('dashboard', 'danger', 'Accesso non autorizzato.');
        }

        $mode      = 'edit';
        $entity_id = (int) get_param('id');

        if ($entity_id <= 0) redirect_with('products', 'danger', 'Prodotto non trovato.');

        $stmt = $this->pdo->prepare('SELECT * FROM prodotti WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $entity_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) redirect_with('products', 'danger', 'Prodotto non trovato.');

        $errors = [];
        $values = ['nome' => $row['nome'], 'tipo' => $row['tipo']];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values['nome'] = trim(post('nome'));
                if ($values['nome'] === '') $errors['nome'] = 'Il nome è obbligatorio.';

                if (empty($errors)) {
                    $this->pdo->prepare('UPDATE prodotti SET nome=:n WHERE id=:id')
                              ->execute([':n' => $values['nome'], ':id' => $entity_id]);
                    redirect_with('products/edit?id=' . $entity_id, 'success', 'Prodotto aggiornato.');
                }
            }
        }

        $page_title  = $values['nome'] ?: 'Modifica prodotto';
        $breadcrumbs = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => $values['nome'] ?: 'Modifica'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/products/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Cancellazione (POST only) ── */
    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products');
        if (!csrf_verify()) redirect_with('products', 'danger', 'Token non valido.');
        if (!can('manage_catalog')) redirect_with('dashboard', 'danger', 'Accesso non autorizzato.');

        $id = (int) post('id');
        if ($id <= 0) redirect_with('products', 'danger', 'Prodotto non trovato.');

        $this->pdo->prepare('DELETE FROM prodotti WHERE id = :id')->execute([':id' => $id]);
        redirect_with('products', 'success', 'Prodotto eliminato.');
    }
}
```

### Vista lista `app/views/products/_list.php`

```php
<?php
/**
 * Variabili attese: $rows (array), $total (int), $search (string)
 */
?>
<div class="qk-card">
  <div class="qk-table-toolbar">
    <div class="qk-table-toolbar-left">
      <form method="get" class="d-flex gap-2 align-items-center">
        <div class="qk-filter-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" name="q" class="qk-filter-input"
                 placeholder="Cerca…" value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-filter"></i> Filtra
        </button>
        <?php if ($search): ?>
        <a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-xmark"></i> Pulisci
        </a>
        <?php endif; ?>
      </form>
    </div>
    <div class="qk-table-toolbar-right">
      <small class="qk-text-muted"><?= $total ?> prodotti</small>
    </div>
  </div>

  <div class="qk-table-wrap">
    <?php if (empty($rows)): ?>
    <div class="qk-empty">
      <i class="fa-regular fa-box"></i>
      <p><?= $search ? 'Nessun risultato.' : 'Nessun prodotto ancora.' ?></p>
    </div>
    <?php else: ?>
    <table class="qk-table">
      <thead>
        <tr><th>Nome</th><th>Tipo</th><th class="col-actions"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="fw-semibold"><?= e($r['nome']) ?></td>
          <td><?= e($r['tipo']) ?></td>
          <td class="col-actions">
            <div class="dropdown">
              <button class="btn-qk btn-qk-secondary btn-qk-sm px-2"
                      data-bs-toggle="dropdown">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <a class="dropdown-item" href="<?= url('products/edit?id=' . $r['id']) ?>">
                    <i class="fa-solid fa-pen fa-fw me-2"></i>Modifica
                  </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="post" action="<?= url('products/delete') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger"
                            data-confirm="Eliminare «<?= e($r['nome']) ?>»?">
                      <i class="fa-solid fa-trash fa-fw me-2"></i>Elimina
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
```

### Vista form `app/views/products/_form.php`

```php
<?php
/**
 * Variabili attese: $values (array), $errors (array),
 *                  $mode ('create'|'edit'), $entity_id (int|null)
 */
$action_url = ($mode === 'edit')
    ? url('products/edit?id=' . (int) $entity_id)
    : url('products/create');
?>

<div class="qk-card" style="max-width:640px">

  <?php if (!empty($errors['_global'])): ?>
  <div class="qk-alert qk-alert-danger mb-4">
    <i class="fa-solid fa-circle-xmark"></i>
    <span><?= e($errors['_global']) ?></span>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= e($action_url) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
      <label class="form-label">Nome <span class="text-danger">*</span></label>
      <input type="text" name="nome"
             class="form-control <?= isset($errors['nome']) ? 'is-invalid' : '' ?>"
             value="<?= e($values['nome']) ?>">
      <?php if (isset($errors['nome'])): ?>
        <div class="invalid-feedback"><?= e($errors['nome']) ?></div>
      <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between pt-3 border-top">
      <a href="<?= url('products') ?>" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Annulla
      </a>
      <button type="submit" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk me-1"></i>
        <?= $mode === 'edit' ? 'Salva modifiche' : 'Crea prodotto' ?>
      </button>
    </div>
  </form>
</div>
```

---

## 7. Funzioni helper disponibili

Tutte definite in `includes/functions.php` — già caricate, non serve `require`.

### Output e URL

| Funzione                 | Cosa fa                                    | Esempio                       |
| ------------------------ | ------------------------------------------ | ----------------------------- |
| `e($str)`                | Escape HTML — **usare SEMPRE nell'output** | `<?= e($nome) ?>`             |
| `url('products/create')` | URL assoluta corretta                      | `→ /Quack/products/create`    |
| `asset('css/app.css')`   | URL di un asset statico                    | `→ /Quack/assets/css/app.css` |

### Input HTTP

| Funzione                   | Cosa fa                                |
| -------------------------- | -------------------------------------- |
| `post('campo', 'default')` | Legge `$_POST['campo']` (già trimmato) |
| `get_param('id')`          | Legge `$_GET['id']` (già trimmato)     |

### Redirect e flash message

| Funzione                                         | Cosa fa                  |
| ------------------------------------------------ | ------------------------ |
| `redirect('products')`                           | Redirect immediato       |
| `redirect_with('products', 'success', 'Fatto!')` | Redirect + flash message |

I tipi di flash: `success`, `danger`, `warning`, `info`. Il messaggio appare automaticamente nella pagina successiva.

### Sicurezza

| Funzione        | Cosa fa                                                                  |
| --------------- | ------------------------------------------------------------------------ |
| `csrf_field()`  | Stampa `<input type="hidden" name="csrf_token" …>` — va in ogni `<form>` |
| `csrf_verify()` | Verifica il token su POST — **obbligatorio** prima di processare dati    |

### Navigazione

| Funzione                          | Cosa fa                                                   |
| --------------------------------- | --------------------------------------------------------- |
| `nav_active('/products')`         | Ritorna `'active'` se l'URL corrente contiene `/products` |
| `is_current_section('/products')` | Bool, per espandere/chiudere i sottomenu                  |

### Formattazione

| Funzione                           | Output                        |
| ---------------------------------- | ----------------------------- |
| `fmt_date('2026-05-09')`           | `09/05/2026`                  |
| `fmt_datetime('2026-05-09 14:30')` | `09/05/2026 14:30`            |
| `fmt_money(1234.5)`                | `€ 1.234,50`                  |
| `truncate($str, 50)`               | Tronca a 50 caratteri con `…` |
| `status_badge('confirmed')`        | Badge HTML colorato           |
| `adr_badge(true)`                  | Badge arancione `⚠ ADR`       |

---

## 8. Come usare il database

All'interno di un controller hai già `$this->pdo` disponibile. Nient'altro da fare.

**Non concatenare mai valori utente nella query. Usa sempre i parametri:**

```php
// ❌ SBAGLIATO — SQL injection
$pdo->query("SELECT * FROM prodotti WHERE nome = '$nome'");

// ✅ GIUSTO
$stmt = $this->pdo->prepare('SELECT * FROM prodotti WHERE nome = :nome');
$stmt->execute([':nome' => $nome]);
```

### Pattern comuni

```php
// Lista
$rows = $this->pdo->query('SELECT * FROM prodotti ORDER BY nome')
               ->fetchAll(PDO::FETCH_ASSOC);

// Record singolo
$stmt = $this->pdo->prepare('SELECT * FROM prodotti WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) redirect_with('products', 'danger', 'Non trovato.');

// Inserimento
$this->pdo->prepare('INSERT INTO prodotti (nome, tipo) VALUES (:n, :t)')
          ->execute([':n' => $nome, ':t' => $tipo]);
$nuovo_id = (int) $this->pdo->lastInsertId();

// Aggiornamento
$this->pdo->prepare('UPDATE prodotti SET nome=:n WHERE id=:id')
          ->execute([':n' => $nome, ':id' => $id]);

// Cancellazione
$this->pdo->prepare('DELETE FROM prodotti WHERE id=:id')
          ->execute([':id' => $id]);

// Transazione (operazioni su più tabelle)
$this->pdo->beginTransaction();
try {
    // query A
    // query B
    $this->pdo->commit();
} catch (Throwable $e) {
    $this->pdo->rollBack();
    throw $e;
}
```

### Tabelle principali del DB

| Tabella                                   | Contenuto                                                     |
| ----------------------------------------- | ------------------------------------------------------------- |
| `utenti`                                  | Account con ruolo e password hash                             |
| `clienti_fornitori`                       | Anagrafiche uniche (campo `tipo`: cliente/fornitore/entrambi) |
| `indirizzi`                               | Indirizzi collegati alle anagrafiche                          |
| `dettagli_clienti` / `dettagli_fornitori` | Dati commerciali specifici                                    |
| `prodotti`                                | Catalogo (semplici e kit)                                     |
| `categorie_prodotto`                      | Albero categorie                                              |
| `adr_sostanze`                            | Lookup sostanze pericolose                                    |
| `schede_sicurezza`                        | Schede SDS/ADR legate ai prodotti                             |
| `giacenze` / `movimenti_magazzino`        | Stock e log carico/scarico                                    |
| `offerte` + `offerte_righe`               | Preventivi                                                    |
| `ordini_clienti` + righe                  | Ordini confermati                                             |
| `ordini_fornitore` + righe                | Ordini verso fornitori                                        |
| `listini` + `listini_prezzi`              | Prezzi di vendita                                             |

Schema completo: `db/init.sql`.

---

## 9. Sistema dei permessi

```php
can(string $permission): bool
```

Controlla se l'utente corrente ha un determinato permesso. Usala all'inizio di ogni metodo controller che richiede autorizzazione.

### Mappa permessi → ruoli

| Permesso           | admin | commerciale | magazziniere | readonly |
| ------------------ |:-----:|:-----------:|:------------:|:--------:|
| `manage_users`     | ✓     |             |              |          |
| `view_costs`       | ✓     | ✓           |              |          |
| `edit_prices`      | ✓     | ✓           |              |          |
| `manage_catalog`   | ✓     |             |              |          |
| `manage_warehouse` | ✓     |             | ✓            |          |
| `manage_orders`    | ✓     | ✓           |              |          |

### Utilizzo

```php
// In un controller — blocca l'accesso
if (!can('manage_catalog')) {
    redirect_with('dashboard', 'danger', 'Accesso non autorizzato.');
}

// In una vista — mostra/nasconde un pulsante
<?php if (can('edit_prices')): ?>
  <a href="..." class="btn-qk btn-qk-primary">Modifica prezzo</a>
<?php endif; ?>

// In sidebar.php — voce condizionale
<?php if (can('manage_users')): ?>
  <li>... link Utenti ...</li>
<?php endif; ?>
```

---

## 10. Componenti CSS disponibili

Tutte le classi sono in `assets/css/app.css`. Evita gli stili inline quando esiste una classe.

### Layout

| Classe             | Uso                                         |
| ------------------ | ------------------------------------------- |
| `qk-card`          | Box contenitore principale di ogni pagina   |
| `qk-table-wrap`    | Wrapper scroll orizzontale per le tabelle   |
| `qk-table-toolbar` | Barra sopra la tabella (filtri + contatore) |
| `qk-pagination`    | Barra sotto la tabella                      |
| `qk-empty`         | Stato vuoto (icona + testo + bottone)       |

### Badge

| Classe               | Uso                             |
| -------------------- | ------------------------------- |
| `qk-badge confirmed` | Verde (attivo, confermato)      |
| `qk-badge draft`     | Grigio (bozza)                  |
| `qk-badge cancelled` | Rosso (annullato, disabilitato) |
| `qk-badge pending`   | Arancione (in attesa)           |
| `qk-adr-tag`         | Badge ⚠ per prodotti ADR        |

### Avvisi

| Classe                      | Uso       |
| --------------------------- | --------- |
| `qk-alert qk-alert-success` | Verde     |
| `qk-alert qk-alert-danger`  | Rosso     |
| `qk-alert qk-alert-warning` | Arancione |

Aggiungi `data-auto-dismiss="4000"` per dismissione automatica dopo 4 secondi.

### Bottoni

| Classe                    | Uso               |
| ------------------------- | ----------------- |
| `btn-qk btn-qk-primary`   | Azione principale |
| `btn-qk btn-qk-secondary` | Azione secondaria |
| `btn-qk btn-qk-sm`        | Versione piccola  |

### Confirm dialog automatico

Aggiungi `data-confirm="Sicuro?"` a qualsiasi `<button type="submit">`: `app.js` mostra il dialog prima di inviare il form.

---

## 11. Report audit — problemi, incoerenze, migliorie

### 🔴 Da risolvere — impatto diretto sul funzionamento

**1. Nessuna paginazione server-side**

Tutti i moduli caricano l'intera tabella con `fetchAll()`. Con dati reali e centinaia di record le pagine diventano lente.

→ **Soluzione futura:** aggiungere `LIMIT :limit OFFSET :offset` alle query e navigazione pagina nella toolbar.

### 🟡 Incoerenze architetturali

**2. Due pattern di rendering nei controller**

`CustomersController` e `SuppliersController` usano l'inline require:

```php
require INCLUDES_DIR . '/header.php';
require APP_ROOT_DIR . '/app/views/customers/_list.php';
require INCLUDES_DIR . '/footer.php';
```

`ProfileController` e `DashboardController` usano `$this->page()`:

```php
$this->page(APP_ROOT_DIR . '/app/views/profile/_index.php', [...]);
```

Entrambi funzionano, ma è incoerente. L'inline require è più diretto (variabili locali disponibili immediatamente), `$this->page()` richiede di passare tutto via array.

→ **Soluzione:** standardizzare sull'inline require (più idiomatico PHP, usato dai controller più complessi). Aggiornare Profile e Dashboard in futuro.

**3. `UsersController` gestisce URL `/utenti`**

Il controller si chiama `UsersController` (inglese) ma le URL sono `/utenti` (italiano). Incoerente con il resto (`CustomersController` → `/customers`, `SuppliersController` → `/suppliers`).

→ Minore — non impatta il funzionamento, ma crea attrito mentale. Da allineare quando si ha tempo (rinomina la classe o le URL).

**3. Tipo route `view` e `action` residui**

`/login` usa `type => 'view'` e `/logout` usa `type => 'action'`, ma funzionano entrambi come "include un file da `app/views/`". Sono due nomi diversi per la stessa cosa.

→ **Soluzione:** unificare i due tipi in `view` (oppure convertire anche login/logout a controller).

**5. `can()` non applicata nella maggior parte dei controller**

La funzione esiste ed è usata in `UsersController`. Ma `CustomersController`, `SuppliersController`, `ProductsController` ecc. non controllano i permessi: chiunque sia autenticato può fare tutto.

→ **Soluzione:** aggiungere `if (!can('...')) redirect_with(...)` all'inizio di ogni metodo sensibile, man mano che si completano i moduli.

### 🟢 Pulizia e organizzazione

**6. CSS non utilizzati**

`assets/css/bulma.min.css` e `assets/css/pico.min.css` non sono referenziati da nessuna parte. Sono il residuo di fasi di prototipazione iniziale.

→ Eliminare entrambi.

**7. File di testo sparsi**

Nella root: `da fare.txt`, `file fantastici e dove trovarli.txt`.  
In `file supporto/`: `prompt ux login.txt`, `prompt ux.txt`, `sostituire q con logo.txt`.

→ Spostare tutto in `file supporto/` (quelli nella root) o eliminarli se superati.

**8. Nessun model layer per i nuovi moduli**

`anagrafiche.php` è un model dedicato per clienti e fornitori. I nuovi moduli (prodotti, ordini, offerte) non hanno nulla di simile — la logica di accesso al DB è tutta inline nei controller. Per moduli semplici va bene, ma per quelli complessi (ordini con righe, offerte con listini) diventa difficile da mantenere.

→ **Soluzione futura:** creare `includes/models/ordini.php`, `includes/models/prodotti.php` ecc. sul modello di `anagrafiche.php`.

**8. Migrazione su Laragon**
Laragon è come xampp ma molto più leggero. Quando l'app è finita, fai la migrazione

**9. vedi se, essendo in locale, puoi evitare la richiesta di certificato https**

---

## 12.+++ Checklist per un nuovo modulo

- [ ] Crea `app/views/nomemodulo/_list.php` (solo HTML)
- [ ] Crea `app/views/nomemodulo/_form.php` (solo HTML)
- [ ] Crea `app/Controllers/NomemoduloController.php` estendendo `BaseController`
- [ ] Aggiungi le route in `app/routes.php` (index, create, edit, delete)
- [ ] Aggiungi redirect `.php` legacy nel blocco "Legacy URL compatibility"
- [ ] Aggiungi il link in `includes/sidebar.php` con `can()` se necessario
- [ ] Controlla permessi all'inizio di ogni metodo con `can()`
- [ ] Usa `csrf_field()` in ogni `<form>`
- [ ] Usa `csrf_verify()` all'inizio di ogni metodo che processa POST
- [ ] Tutti i link interni usano `url('nomemodulo/azione')` senza `.php`
- [ ] Testa create → lista → edit → delete con dati reali
- [ ] Aggiorna questo documento se hai modificato qualcosa di strutturale

---

*Fine guida — versione maggio 2026.*
