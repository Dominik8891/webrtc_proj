# Sicherheitsbericht: Rechteprüfung und Rollenmodell

**Stand:** Branch `claude/guide-admin-rights-audit-8hph7a`, Basis `a20307a`
("Rollen zentral normalisieren, Verfuegbarkeit zuverlaessig machen")
**Umfang:** Statische Durchsicht aller Rollen- und Zugriffsprüfungen in
`class/`, `config/`, `index.php`, `assets/js/`.
**Es wurde nichts geändert.** Dieser Bericht ist die einzige neue Datei.

---

## 0. Kurzfassung

Der Befund des Nutzers ist bestätigt und schlimmer als beschrieben.

`UserController::manageUser()` prüft in Zeile 26 mit `> 1` gegen die
Rollen-ID. Admin ist 0, Guide ist 1 — `1 > 1` ist falsch, der Guide fällt
also durch die Sperre hindurch. Ab dort kann er über das ganz normale
Formular `manage_user` **jedes Konto bearbeiten, einschließlich Rolle und
Passwort**. Er kann sich damit selbst auf `type_id = 0` (Admin) setzen oder
dem echten Admin ein neues Passwort geben. Aus "Guide" wird in zwei
HTTP-Requests "Admin".

Daneben stehen drei weitere Probleme derselben Familie:

* `UserController::deleteUser()` (Zeile 130-135) prüft **überhaupt nichts** —
  weder Rolle noch Login. Jeder Aufruf von
  `index.php?act=delete_user&user_id=N` löscht das Konto N.
* `UserController` Zeile 109 und 236 prüfen `=== 1`. Das ist die **falsche
  Rolle**: Der Guide sieht die Adminspalten, der echte Admin (0) sieht sie
  nicht.
* `LocationController::deleteLocation()` und `::editLocationDesc()` prüfen
  weder Login noch Eigentum. Jeder kann jeden fremden Standort ändern oder
  löschen.

`Role::isAdmin()` existiert in `class/Helper/Role.php:103` und ist **im
gesamten Produktivcode nie aufgerufen**. Die korrekte Prüfung liegt fertig
da und wird nicht benutzt.

Zur Frage "durch `fix/rollen-und-verfuegbarkeit` entstanden?": **Nein, die
kaputte Prüfung ist über ein Jahr alt.** Aber dieser Commit hat sie zum
ersten Mal erreichbar gemacht. Details in Abschnitt 3.

---

## 1. Jede Stelle, an der Rechte geprüft werden

### 1.1 Zentrale Definitionen

| Datei:Zeile | Code | Bewertung |
|---|---|---|
| `class/Helper/Role.php:29-32` | `ADMIN = 0; GUIDE = 1; USER = 2; TRIAL = 3` | Deckt sich mit `database.sql:22-26`. Korrekt. |
| `class/Helper/Role.php:103-106` | `isAdmin($role) { return self::id($role) === self::ADMIN; }` | **Fachlich korrekt** — exakter Vergleich, keine Rangfolge. **Wird nirgends aufgerufen** (verifiziert per `grep -rn "isAdmin\|Role::ADMIN" --include=*.php`, einziger Treffer ist die Definition selbst). |
| `class/Helper/Role.php:93-96` | `isGuide()` | Korrekt, exakter Vergleich. |
| `class/Helper/Role.php:115-118` | `mayOfferLocation()` — `$id === ADMIN \|\| $id === GUIDE` | Korrekt. Erstes echtes Berechtigungsprädikat im Projekt. |
| `class/Helper/Role.php:129-132` | `mayBecomeGuide()` — `$id === USER \|\| $id === TRIAL` | Korrekt. |

Die zentrale Stelle ist also in Ordnung. Das Problem ist, dass die
Controller sie für Adminfragen nicht benutzen.

### 1.2 Die fehlerhaften Prüfungen

#### F-1 — Rangfolge statt Rolle (die vom Nutzer gemeldete Stelle)

**`class/Controller/UserController.php:26`**

```php
if ($_SESSION['user']['role_id'] > 1) {
    SystemController::home();
    exit;
}
```

Der Kommentar darüber sagt es offen — **`class/Controller/UserController.php:16`**:

```php
 * Zugang nur für eingeloggte Admins (RoleId <= 1).
```

`RoleId <= 1` heißt "Admin **oder Guide**". Die Rangfolge-Annahme ist hier
nicht versehentlich entstanden, sie ist so dokumentiert. Wer sie geschrieben
hat, ist davon ausgegangen, dass kleinere Zahl = mehr Rechte und dass Guide
zum Adminbereich gehört.

Zwei zusätzliche Schwächen derselben Zeile:

* Ist `role_id` **`null`**, ergibt `null > 1` in PHP `false` — der Zugang ist
  offen. `LoginController.php:86` schreibt `Role::id($user->getRoleId())` in
  die Session, und `Role::id()` liefert laut `Role.php:73` bei unbekannter
  Rolle `null`. Ein Konto mit einer `type_id`, die nicht in `usertype` steht,
  käme also durch. Der Fremdschlüssel `user_ibfk_1` (`database.sql:376`)
  macht das derzeit unwahrscheinlich, die Prüfung trägt aber nicht.
* Der Session-Schlüssel hat **zwei verschiedene Formen**, je nach Loginweg:
  `LoginController.php:86` schreibt eine normalisierte `int|null`,
  `TwoFactorController.php:188` schreibt mit `$user->getUserDetails()` den
  **rohen** Wert aus der Datenbank (`User.php:540`). Für `> 1` macht das im
  Moment keinen Unterschied, für jede künftige `===`-Prüfung aber sehr wohl.

#### F-2 — Falsche Rolle: Guide bekommt die Adminspalten, Admin nicht

**`class/Controller/UserController.php:109`**

```php
if ($user->getRoleId() === 1) {
    $action = "<th>Aktion</th>";
    $email  = '<th class="user_table_desktop">Email</th>';
    $new    = '<a href="index.php?act=manage_user" ...>Neuen Benutzer anlegen</a>';
}
```

**`class/Controller/UserController.php:236`**

```php
if ($in_user->getRoleId() === 1) {
    $action = $this->getAction($tmp_user);   // Ändern-/Löschen-Buttons
    $email  = htmlspecialchars($tmp_user->getEmail());
}
```

`1` ist Guide. Der Admin mit `type_id = 0` erfüllt die Bedingung **nicht**
und sieht die Benutzerverwaltung nicht. Der Guide sieht sie. Das ist keine
zu weite Prüfung, sondern eine schlicht vertauschte.

Nebenwirkung: Über `getAction()` (`UserController.php:281-287`) und die
E-Mail-Spalte gibt die Benutzerliste dem Guide die **E-Mail-Adressen aller
Nutzer** aus.

`=== 1` trifft nur zu, wenn `getRoleId()` einen echten `int` liefert.
`PdoConnect.php:41` setzt `ATTR_EMULATE_PREPARES => false`; mit mysqlnd
kommen `int(11)`-Spalten als PHP-`int` zurück, die Bedingung greift also.
*Unsicherheit:* Unter libmysqlclient statt mysqlnd käme `'1'` als String und
`=== 1` wäre `false`. Das ist genau die Klasse von Zufall, gegen die
`Role::id()` gebaut wurde — hier wird sie nicht benutzt.

#### F-3 — Gar keine Prüfung: Benutzer löschen

**`class/Controller/UserController.php:130-135`**

```php
public function deleteUser()
{
    $tmp_user = new User(Request::g('user_id'));
    $tmp_user->del_it();
    $this->listUser();
}
```

Kein Login-Check, kein Rollen-Check, kein CSRF-Token. Route
`delete_user` (`config/routes.php:37`) ist ohne jede Vorbedingung erreichbar,
`index.php:43-46` ruft die Methode direkt auf. `del_it()`
(`User.php:143-148`) setzt `deleted = 1`.

Das trifft **nicht nur Guides** — ein anonymer Aufruf von
`index.php?act=delete_user&user_id=1` genügt.

#### F-4 — Gar keine Prüfung: fremde Standorte

**`class/Controller/LocationController.php:151-172` (`editLocationDesc`)**
**`class/Controller/LocationController.php:179-206` (`deleteLocation`)**

Beide holen nur `Request::g('id')` und arbeiten damit. Kein
`$_SESSION['user']`-Zugriff, keine Eigentumsprüfung.

Im Model fehlt die zweite Verteidigungslinie ebenfalls:

* `class/Model/Location.php:113-132` — `UPDATE location SET ... WHERE id = :id`
* `class/Model/Location.php:138-150` — `DELETE FROM location WHERE id = :id`

In keinem der beiden Statements steht `user_id`. Die Spalte existiert
(`database.sql:390`, `NOT NULL`) und wird beim Anlegen befüllt
(`Location.php:93-96`), beim Ändern und Löschen aber ignoriert.

#### F-5 — Chat-Routen ohne Teilnehmerprüfung

| Datei:Zeile | Methode | Befund |
|---|---|---|
| `class/Controller/ChatController.php:111-149` | `getMessages()` | Liest `chat_id` aus dem Request und gibt **alle Nachrichten** aus. Kein `$_SESSION`-Zugriff in der ganzen Methode. |
| `class/Controller/ChatController.php:61-71` | `acceptChat()` | Setzt beliebige `chat_id` auf aktiv. Keine Prüfung. |
| `class/Controller/ChatController.php:215-228` | `setMessagesSeen()` | `UPDATE chat_message ... WHERE chat_id = ?` für beliebige Chat-ID. |
| `class/Controller/UserController.php:167-179` | `getUsername()` | Liefert Benutzernamen zu beliebiger ID, ohne Login. (Liegt im `UserController`, gehoert aber zum selben Muster.) |

Positiv, zur Abgrenzung: `showChat()` (`ChatController.php:281-284`) und
`declineChat()` (`ChatController.php:202-205`) prüfen die Teilnahme korrekt
und ohne Rangfolge — das ist genau das Muster, das überall fehlt. Beide
vergleichen mit `!=` statt `!==`; bei numerischen IDs ist das unkritisch,
für eine einheitliche Schreibweise gehörte es aber angeglichen.

#### F-6 — Adminseite ohne Prüfung

**`class/Controller/SystemController.php:18-21`**

```php
public function showAdmin($msg = "Willkommen im Admin Panel"): void
{
    ViewHelper::output($msg);
}
```

Route `admin` (`config/routes.php:30`), keine Prüfung. Die Auswirkung ist
gering — die Methode gibt nur einen Text aus, es hängt keine Funktion daran.
Ich führe sie auf, weil sie als Einstiegspunkt vorhanden ist und beim
Ausbauen unbemerkt zur Lücke würde.

### 1.3 Korrekte Prüfungen (zur Abgrenzung)

| Datei:Zeile | Prüfung |
|---|---|
| `class/Controller/LocationController.php:70` | `Role::mayBecomeGuide($user->getRoleId())` — explizites Prädikat |
| `class/Controller/LocationController.php:19`, `:138` | `!empty($_SESSION['user']['user_id'])` — Login-Prüfung |
| `class/Helper/ViewHelper.php:101-102` | `mayOfferLocation()` / `mayBecomeGuide()` für die Frontend-Flags |
| `class/Controller/WebRTCController.php:205` | `Role::isGuide()` — betrifft die **Call-Rolle**, nicht Rechte |
| `class/Controller/UserController.php:189-192` | `saveLocation()` prüft Login und antwortet mit 401 |
| `class/Controller/SettingsController.php:18-22` | Login-Prüfung mit Redirect |
| `class/Controller/ChatController.php:23-28`, `:79-83`, `:157-163`, `:232-236` | Login-Prüfung |

### 1.4 Struktureller Befund: kein Ort für Rechte

`index.php:43-46` ruft die Controller-Methode direkt auf:

```php
[$class, $method] = $routes[$act];
$controller = new $class();
$controller->$method();
```

`config/routes.php` ist eine reine `act => [Klasse, Methode]`-Tabelle ohne
Rechte-Spalte. **Es gibt keine Stelle, an der die Berechtigung einer Route
zentral hinterlegt ist.** Jede Prüfung ist handgeschriebener Code in der
ersten Zeile der jeweiligen Methode — und wo sie vergessen wurde, fällt es
niemandem auf, weil nichts sie erzwingt. Das ist die eigentliche Ursache von
F-3 bis F-6.

---

## 2. Was ein Guide dadurch erreicht

Ausgangslage: Ein Konto wird bei der Registrierung als `type_id = 3` (Trial)
angelegt — `User.php:73-74` schreibt die 3 fest in das INSERT, das
`type_id`-Feld aus `signup.html:5` wird von `SignupController::handleSignup()`
gar nicht gelesen. Über `index.php?act=set_location_page` legt der Nutzer
einen Standort an, `LocationController.php:70-77` stuft ihn auf
`Role::GUIDE` (1) hoch und frischt die Session auf. Ab hier gilt:

### 2.1 Benutzerverwaltung — vollständige Übernahme *(kritisch)*

`index.php?act=manage_user` besteht die Prüfung in Zeile 26 (`1 > 1` ist
falsch). Das Formular `assets/html/manage_user.html` wird ausgeliefert.

Beim Speichern (`UserController.php:48-77`):

```php
$sel_user = new User(Request::g('id'));      // Zeile 49 - beliebige Fremd-ID
$role     = Request::g('role', null);        // Zeile 61
...
if ($role !== null) $sel_user->setRoleId($role);          // Zeile 66
if ($pwd  !== null && $pwd !== '') $sel_user->setPwd(...); // Zeile 72
$sel_user->save();                                         // Zeile 74
```

`Request::g('id')` kommt aus `$_REQUEST` (`Request.php:37`) und ist frei
wählbar — das versteckte Feld `id` in `manage_user.html:5` ist nur die
Voreinstellung. `User::update()` (`User.php:97-120`) schreibt `type_id` und
`pwd` ohne weitere Prüfung.

Damit kann ein Guide:

1. **sich selbst zum Admin machen** — `id` = eigene ID, `role` = `0`. Der Wert
   ist dauerhaft in der Datenbank.
2. **das Admin-Passwort setzen** — `id` = ID des Admins, `pwd` = beliebig.
   `setPwd($sel_user->pwdEncrypt($pwd))` in Zeile 72 hasht korrekt, das
   Ergebnis ist ein funktionierendes Login als Admin.
3. **jede fremde E-Mail-Adresse ändern** (Zeile 68) — in Verbindung mit
   `forgot_pw` ein zweiter Übernahmeweg.
4. **fremde Benutzernamen ändern** (Zeile 67).

Es gibt kein CSRF-Token, das Formular ist ein reines POST — Punkt 1 bis 4
funktionieren auch als CSRF gegen einen eingeloggten Guide.

### 2.2 Benutzerliste — E-Mail-Adressen aller Nutzer

`index.php?act=list_user`, `UserController.php:109` und `:236`. Der Guide
sieht die Spalte "Email" und die Aktionsbuttons. Der Admin nicht.

### 2.3 Benutzer löschen

`index.php?act=delete_user&user_id=N` — `UserController.php:130-135`, keine
Prüfung. Für den Guide erreichbar; **und für jeden anderen ebenso, auch ohne
Login**.

### 2.4 Fremde Standorte ändern und löschen

`index.php?act=delete_location` (POST `id`) und
`index.php?act=edit_location_desc` (POST `id`, `description`) —
`LocationController.php:179` und `:151`, keine Prüfung, und im Model
(`Location.php:113`/`:138`) auch nicht. Ein Guide kann die Standorte aller
anderen Guides löschen. Auch das ist nicht auf Guides beschränkt: Die Routen
prüfen den Login nicht.

Die Aufrufe stehen in `assets/js/locations_table.js:485` und `:552`.

### 2.5 Fremde Chats

`index.php?act=chat_get_messages&chat_id=N` — `ChatController.php:111`.
Vollständiger Nachrichtenverlauf jedes beliebigen Chats, ohne Login.
Zusätzlich `chat_accept` und `chat_set_seen` (Abschnitt F-5).

*Hinweis:* Über den bestimmungsgemäßen Weg `show_chat`
(`ChatController.php:281`) ist der Zugriff korrekt abgesichert. Die Lücke
liegt ausschließlich in der JSON-Route.

### 2.6 Adminbereich

`index.php?act=admin` gibt "Willkommen im Admin Panel" aus. Ohne Funktion,
also ohne unmittelbaren Schaden.

### Einordnung

Der schwerste Punkt ist 2.1. Die anderen sind für sich genommen ebenfalls
Lücken, ändern aber nichts mehr daran, dass ein Guide bereits vollwertiger
Admin werden kann.

---

## 3. Ist das durch `fix/rollen-und-verfuegbarkeit` entstanden?

**Der Fehler ist deutlich älter. Der Commit hat ihn ausnutzbar gemacht.**

### 3.1 Herkunft der Prüfungen (`git blame`)

```
fa4e6708  dominik8891  2025-06-10  UserController.php:26   if ($_SESSION['user']['role_id'] > 1)
fa4e6708  dominik8891  2025-06-10  UserController.php:109  if ($user->getRoleId() === 1)
aa3b1a9e  dominik8891  2025-06-23  UserController.php:236  if ($in_user->getRoleId() === 1)
```

`git show --stat a20307a` zeigt: **`UserController.php` ist in diesem Commit
nicht enthalten.** `git show a20307a -- class/Controller/UserController.php`
liefert einen leeren Diff. Der Commit hat an der Rechteprüfung keine Zeile
angefasst.

### 3.2 Was sich trotzdem geändert hat

Vor `a20307a` sah der Aufstieg so aus (`git show a20307a^:class/Controller/LocationController.php`):

```php
$user = new User($user_id);
if ($user->getUsertype() === 'tourist') {
    $user->setUsertype('guide');
    $user->save();
}
```

`usertype.name` enthält laut `database.sql:22-26` `'Admin'`, `'Guide'`,
`'User'`, `'Trial'`. **`'tourist'` existiert dort nicht.** Die Bedingung war
niemals wahr, der Aufstieg fand nie statt. Genau das ist Befund F-6 der
Bestandsaufnahme, und genau das hat `a20307a` behoben —
`LocationController.php:70-77`:

```php
if (Role::mayBecomeGuide($user->getRoleId())) {
    $user->setRoleId(Role::GUIDE);
    $user->save();
    $_SESSION['user']['role_id'] = Role::GUIDE;
}
```

Zusätzlich war der Button unsichtbar: `assets/js/ui.js` verglich vor dem
Commit `window.userRole === 'admin' || === 'guide'` gegen einen Wert, der
`'Admin'`/`'Guide'` lautete, und `=== 'tourist'` gegen einen nie
existierenden Wert.

### 3.3 Bewertung

Vor `a20307a` gab es in der Praxis **keine Guides** — Konten blieben auf
Trial (3) oder User (2) stehen, und `3 > 1` bzw. `2 > 1` schließt die
Adminsperre korrekt. Die Lücke in Zeile 26 lag seit dem 10. Juni 2025 da,
war aber nur für ein manuell in der Datenbank auf `type_id = 1` gesetztes
Konto erreichbar.

`a20307a` hat den Aufstiegspfad repariert. Damit wurde `type_id = 1` zum
ersten Mal über die normale Oberfläche erreichbar — **und die alte, kaputte
Prüfung dahinter zum ersten Mal wirksam.** Der Commit hat den Fehler nicht
eingeführt, aber die Bedingung geschaffen, unter der er ausnutzbar ist.

Die Zeile `$_SESSION['user']['role_id'] = Role::GUIDE;`
(`LocationController.php:77`) sorgt außerdem dafür, dass die neuen Rechte
**sofort** greifen, ohne erneutes Login.

Das ist kein Vorwurf an den Commit — er hat getan, was er sollte. Er hat
sichtbar gemacht, dass die Rechteprüfung nie getragen hat.

*Unsicherheit:* Ich kann aus dem Repository nicht erkennen, ob in der
produktiven Datenbank vor dem Commit bereits Konten mit `type_id = 1`
existierten. Falls ja, war die Lücke auch vorher schon offen. Das ließe sich
nur mit `SELECT id, username, type_id FROM user WHERE type_id <= 1;` gegen
die Produktivdatenbank klären.

---

## 4. Vorschlag: Rechtemodell ohne Rangfolge

**Noch nicht umgesetzt.** Das ist der Entwurf zur Abstimmung.

### 4.1 Leitgedanke

Der Fehler ist nicht die Zahl `1`. Der Fehler ist, dass der Code eine Frage
stellt, die er nicht meint. Zeile 26 fragt *"welchen Rang hat dieser
Nutzer?"*. Gemeint ist *"darf dieser Nutzer Benutzerkonten verwalten?"*.

Solange die Frage nach dem Rang gestellt wird, ist jede neue Rolle ein
Risiko: Sie landet irgendwo in der Zahlenreihe und erbt damit stillschweigend
Rechte, über die nie jemand entschieden hat. Eine Rolle `4 = Moderator` würde
sich vielleicht harmlos anfühlen — eine Rolle `-1` oder eine Umnummerierung
von `usertype` wäre eine sofortige Rechteausweitung.

Deshalb: **Berechtigungen benennen, nicht Rollen vergleichen.** Es gibt genau
zwei erlaubte Prüfformen im gesamten Code:

* `Permission::check('manage_users')` — darf die Rolle das grundsätzlich?
* ein Eigentumsvergleich `$row['user_id'] === $currentUserId` — ist es das
  eigene Objekt?

`<`, `<=`, `>`, `>=` auf einer Rollen-ID kommen nirgends mehr vor. Ein
`grep -n '\$.*role.*[<>]' class/` muss dauerhaft leer bleiben, und das lässt
sich als Test festschreiben.

### 4.2 Die Berechtigungstabelle

Neu in `class/Helper/Permission.php`, eine einzige Tabelle als einzige
Wahrheit:

```php
private const GRANTS = [
    Role::ADMIN => [
        'users.list', 'users.manage', 'users.delete', 'users.see_email',
        'locations.offer', 'locations.edit_any', 'locations.delete_any',
        'chats.read_any',
    ],
    Role::GUIDE => [
        'users.list',
        'locations.offer', 'locations.edit_own', 'locations.delete_own',
    ],
    Role::USER  => ['users.list'],
    Role::TRIAL => ['users.list'],
];
```

Eigenschaften, die den Rangfehler ausschließen:

* **Kein Erben.** Guide bekommt nicht "alles ab Stufe 1". Jede Zeile steht
  ausgeschrieben da. Das ist redundanter — und genau deshalb sicher: Ein
  Recht, das ein Guide hat, steht in seiner Zeile und ist im Review sichtbar.
* **Unbekannte Rolle heißt kein Recht.** `Role::id()` liefert bei
  Unbekanntem `null` (`Role.php:73`). `Permission::check()` gibt bei `null`
  hart `false` zurück — nicht wie heute `null > 1 === false`, wo das
  Durchfallen ein Zufall der Vergleichsregeln ist.
* **Eine neue Rolle bekommt nichts, bis jemand sie einträgt.** Fehlt ein
  Eintrag in `GRANTS`, hat die Rolle keine Rechte. Der sichere Zustand ist
  der Standardzustand.

Die Trennung `edit_own` / `edit_any` bildet den Unterschied ab, den das
Projekt heute nirgends kennt: Ein Guide darf **seine** Standorte pflegen, ein
Admin **alle**.

### 4.3 Die Prüf-API

```php
Permission::check(string $permission): bool     // aus der Session, still
Permission::require(string $permission): void   // 403 + Abbruch, für Routen
Permission::owns(string $ownerId): bool         // Eigentumsvergleich, ===
```

`require()` beendet die Anfrage mit HTTP 403 statt mit dem heutigen
`SystemController::home(); exit;`. Der Redirect auf die Startseite
verschleiert, ob ein Recht fehlte oder die Route nicht existiert — das
erschwert genau die Prüfung, die diesen Bericht ausgelöst hat.

`Permission::check()` liest die Rolle über `Role::id($_SESSION['user']['role_id'])`.
Damit ist es egal, ob `LoginController.php:86` einen `int` oder
`TwoFactorController.php:188` einen String hineingeschrieben hat.

### 4.4 Rechte an die Route hängen, nicht in den Controller

Der strukturelle Kern (siehe 1.4): `config/routes.php` bekommt eine dritte
Spalte, und `index.php` erzwingt sie.

```php
// config/routes.php
'manage_user'     => [UserController::class,     'manageUser',     'users.manage'],
'delete_user'     => [UserController::class,     'deleteUser',     'users.delete'],
'list_user'       => [UserController::class,     'listUser',       'users.list'],
'delete_location' => [LocationController::class, 'deleteLocation', '@auth'],
'get_country'     => [LocationController::class, 'getCountry',     '@public'],
```

```php
// index.php
[$class, $method, $need] = $routes[$act];
if ($need !== '@public') {
    if (!isset($_SESSION['user']['user_id'])) { /* 401 */ }
    if ($need !== '@auth') Permission::require($need);
}
$controller = new $class();
$controller->$method();
```

Der entscheidende Punkt: **Der dritte Eintrag ist Pflicht.** Fehlt er, wirft
das Destructuring einen Fehler und die Route ist tot. Eine Route ohne
gesetztes Recht kann es nicht mehr geben. Genau so wären F-3 (`delete_user`),
F-4 (`delete_location`) und F-5 (`chat_get_messages`) nie entstanden — sie
sind nicht durch eine falsche Prüfung entstanden, sondern dadurch, dass
Prüfen freiwillig war.

`@public` muss bewusst hingeschrieben werden. Beim Review ist jede
öffentliche Route eine sichtbare Entscheidung.

### 4.5 Eigentum gehört ins SQL, nicht nur in den Controller

Für `edit_own`/`delete_own` reicht eine Controller-Prüfung nicht — sie ist
die Sorte Prüfung, die beim nächsten Refactoring wieder verschwindet. Das
Eigentum gehört in die `WHERE`-Klausel:

```php
// Location::deleteLocation($actorId, bool $any)
$query = $any
    ? "DELETE FROM location WHERE id = :id"
    : "DELETE FROM location WHERE id = :id AND user_id = :actor";
```

Löscht die Anweisung null Zeilen, war es nicht der eigene Standort — das
Model meldet das, statt `true` zurückzugeben. Heute liefert
`Location.php:150` unbesehen `true`, auch wenn nichts gelöscht wurde.

Dasselbe Muster für `chat_message` (`chat_id` gegen die Teilnahme) und für
`UserController::manageUser()`: Ohne `users.manage` darf `id` nur die eigene
ID sein.

### 4.6 Was die Umsetzung absichern müsste

* **Test gegen die Rangfolge:** ein Test, der `class/` nach
  Vergleichsoperatoren auf Rollenwerten durchsucht und bei einem Treffer
  fehlschlägt. Der eigentliche Fehler dieses Berichts wäre damit dauerhaft
  ausgeschlossen. `tests/server_test.php` bietet sich an, dort steht ab
  Zeile 259 bereits die Rollen-Testgruppe.
* **Test je Rolle und Route:** für jede der vier Rollen die Liste der
  erlaubten `act`-Werte festschreiben. Ein neues Recht für den Guide muss
  einen Test ändern, sonst ist es keine Entscheidung, sondern ein Versehen.
* **Test auf Vollständigkeit:** jede Route in `config/routes.php` hat einen
  dritten Eintrag, und jedes dort genannte Recht existiert in `GRANTS`.
* **CSRF-Token** für alle zustandsändernden Routen. Kein Teil des
  Rollenmodells, aber `manage_user` und `delete_user` sind heute reine
  GET-/POST-Aufrufe ohne Token — die Rechteprüfung allein schützt nicht,
  wenn ein Admin auf einen fremden Link klickt.

### 4.7 Reihenfolge bei der Umsetzung

Falls das Modell so beschlossen wird, wäre die Reihenfolge nach Dringlichkeit:

1. `UserController.php:26` auf `Permission::require('users.manage')` — das ist
   die aktive Eskalation.
2. `UserController::deleteUser()` absichern — offen für jeden, auch anonym.
3. `LocationController::deleteLocation()` / `::editLocationDesc()` samt
   `WHERE user_id` im Model.
4. `ChatController::getMessages()`, `acceptChat()`, `setMessagesSeen()`.
5. `UserController.php:109` und `:236` von `=== 1` auf
   `Permission::check('users.see_email')` bzw. `'users.manage'` — behebt
   nebenbei, dass der Admin die Verwaltung nicht sieht.
6. Routentabelle und `index.php` umstellen, Tests nachziehen.

Die Punkte 1 bis 5 sind kleine, lokale Änderungen und ließen sich sofort
machen. Punkt 6 ist der eigentliche Umbau, der verhindert, dass 1 bis 5
wieder auftreten.

---

## 5. Wo ich unsicher bin

* **Produktivdaten.** Ob vor `a20307a` bereits Konten mit `type_id ≤ 1`
  existierten, ist aus dem Repository nicht erkennbar (Abschnitt 3.3). Das
  entscheidet, ob die Lücke real ausnutzbar war oder nur theoretisch offen
  stand. Zu klären mit
  `SELECT id, username, type_id FROM user WHERE type_id <= 1;`.
* **Ob bereits ausgenutzt.** Ich habe keine Logs geprüft. Falls Zugriffslogs
  vorliegen, wären `act=manage_user` und `act=delete_user` von Konten mit
  `type_id = 1` die relevanten Einträge.
* **PDO-Typrückgabe.** `=== 1` in `UserController.php:109`/`:236` greift nur,
  wenn `type_id` als `int` zurückkommt. Mit `ATTR_EMULATE_PREPARES => false`
  (`PdoConnect.php:41`) und mysqlnd ist das der Fall; unter libmysqlclient
  wäre es ein String und die Bedingung nie wahr. Prüfbar mit
  `var_dump((new User(1))->getRoleId());` auf dem Zielsystem. Für die
  Kernlücke (Zeile 26) ist das ohne Belang — `> 1` verhält sich bei `'1'` und
  `1` gleich.
* **Deployment.** Ob `.htaccess` produktiv greift, hängt von `AllowOverride`
  ab; unter nginx wirkt sie gar nicht. Das betrifft nicht die Rechteprüfung,
  wohl aber die Erreichbarkeit von `database.sql` und `.env`.
* **Frontend-Reichweite.** Ich habe die JS-Aufrufe der betroffenen Routen
  gelesen (`locations_table.js:485`, `:552`), aber nicht im Browser
  ausgeführt. Die Bewertung stützt sich auf den Serverpfad — der ist für die
  Sicherheit maßgeblich, das Frontend ist keine Schranke.
