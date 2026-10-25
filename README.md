# youwilllikeit/silverstripe-carehome-roster

Raspored smjena za [`youwilllikeit/silverstripe-carehome`](https://github.com/you-will-like-it/silverstripe-carehome) (Silverstripe CMS 6, PHP 8.3+).

Sadrži:

- **Radnike**: kvalifikacija, vrsta ugovora, datumi zaposlenja, ugovor na određeno.
- **Tipove smjena**, obrasce rotacije (npr. 12-24-12-48) i minimalnu popunjenost.
- **Mjesečni planer** (`/raspored-plan`): grid radnik × dan, provjera pravila radnog vremena, kopiranje sedmice ili mjeseca, popunjavanje rotacijom, otvorene smjene i zamjene.
- **Workflow mjeseca**: nacrt → objavljen → zaključan.
- **Odsustva i zahtjeve**: godišnji odmor sa saldom i prenosom, bolovanje bez dijagnoze.
- **Zamjene smjena** po principu radnik → kolega → planer.
- **Radničku stranicu** `/raspored` (isti PIN): moj raspored, zahtjevi, zamjene, obavještenja.
- **Izvještaj o satima** (ekran/CSV/PDF) i PDF plana za oglasnu tablu.
- **Obavještenja** u aplikaciji i, opcionalno, e-mailom.

Sve izmjene idu u audit log osnovnog modula.

## Instalacija

```bash
composer require youwilllikeit/silverstripe-carehome-roster
vendor/bin/sake db:build --flush
```

`db:build`:

- dodaje prava grupama osnovnog modula;
- jednom popunjava prazne šifarnike (seed za **FBiH**):
  - kvalifikacije;
  - 2 vrste ugovora;
  - tipove smjena iz `ShiftResolver` konfiguracije, plus `D12`, `N12` i dvije kuhinjske;
  - obrasce 12-24-12-48 i 2-2-2;
  - vrste odsustva;
  - državne praznike FBiH.

Šifarnik koji već ima zapise se nikad ne dira.

Cron, jednom dnevno:

```
15 6 * * *  www-data  cd /var/www/carehome && vendor/bin/sake tasks:carehome-roster-daily
```

Cron radi tri stvari:

- upozorava na ugovore koji ističu;
- upozorava na nepopunjene smjene u naredna 2 dana;
- kreira pravo na godišnji odmor za tekuću godinu, sa prenosom iz prethodne.

**Osnovni modul:** potreban je `youwilllikeit/silverstripe-carehome` ^1.0. Traka „Moj raspored", blok „Danas u smjeni" i suženi izbor primaoca u primopredaji koriste njegove tačke proširenja `updatePortalNavLinks`, `updatePortalSlot` i `updateHandoverReceivers`.

**E-mail:** standardni Silverstripe mailer (`MAILER_DSN` u `.env`). Bez maila ostaju obavještenja u aplikaciji. Pošiljalac se postavlja kroz `RosterNotifier.from_address`.

## Uloge i prava

| Pravo | Grupa | Šta omogućava |
|---|---|---|
| `CAREHOME_ROSTER_PLAN` | Administratori | planer, odsustva, zamjene, šifarnici, pravila |
| `CAREHOME_ROSTER_REPORTS` | Administratori | izvještaj o satima, PDF |
| `CAREHOME_ROSTER_SELF` | sve tri grupe | `/raspored` |

Planiraju se svi članovi sa ulogom (Njegovatelj, Kuhinja, Administrator) koji nemaju oznaku „Ne planira se". Tip smjene određuje za koje se uloge može koristiti. Planer prikazuje jednu grupu odjednom i opcionalno filtrira po zoni.

**Zone** dolaze iz osnovnog modula (`MemberZoneAssignment`). Radnik u smjeni pokriva sve svoje zone. Minimalna popunjenost po zoni broji radnike kojima je dodijeljena ta zona ili zona iznad nje.

## Pravila planiranja

| Situacija | Ishod |
|---|---|
| Odobreno odsustvo, nije zaposlen, pogrešna uloga, zaključan mjesec, preklapanje smjena | **blokira** |
| Dnevni odmor, sedmični odmor, trajanje smjene, sedmični sati, uzastopni dani | upozorenje |
| Zahtjev za odsustvo na čekanju, želja za slobodan dan | upozorenje |

Upozorenje se snima samo uz **razlog**. Razlog ostaje na dodjeli (`OverrideReason`) i u audit logu.

**Vrijednosti pravila** su u CMS-u: Raspored – postavke → Pravila i dodaci. Seed FBiH je startna vrijednost, a ne pravni savjet; uskladite je sa zakonom i kolektivnim ugovorom. Vrijednost 0 isključuje pojedinačno pravilo.

| Pravilo | FBiH seed |
|---|---|
| Dnevni odmor | 12 h |
| Sedmični odmor | 24 h neprekidno |
| Trajanje smjene | 12 h |
| Sedmično | 50 h |
| Uzastopnih dana | 6 |
| Noćni rad | 22–06 |

**Konvencije pri računanju:**

- Smjena pripada danu, sedmici i mjesecu u kojem počinje.
- Sedmični odmor se provjerava samo kad su planirane i susjedne sedmice. Neplanirano vrijeme se računa kao slobodno, pa djelimičan plan ne daje lažna upozorenja.
- Smjena čuva **snapshot** vremena i pauze. Kasnija promjena tipa smjene ne mijenja stari plan niti sate.

**Mjesec:**

- **Nacrt**: radnici ga ne vide.
- **Objava**: obavještava sve radnike koji imaju smjene. Svaka kasnija izmjena se označava „●" (`ChangedAfterPublish`) i šalje obavještenje pogođenom radniku.
- **Zaključavanje**: zabranjuje sve izmjene, uključujući odsustva u tom mjesecu. Otključava samo superadmin, uz razlog.

## Odsustva

Vrste su konfigurabilne. Svaka vrsta ima sljedeće postavke:

- „samo želja" (nikad ne blokira, ne traži odobrenje);
- „potrebno odobrenje";
- „radnik može sam upisati";
- „troši godišnji odmor";
- „plaćeno" (umanjuje normu u izvještaju);
- „bolovanje".

**Ko unosi:**

- **Radnik** u `/raspored`. Vrsta koja traži odobrenje ide na čekanje, a ostale važe odmah (npr. bolovanje). Bolovanje se može prijaviti do 3 dana unazad (`AbsenceService.sick_backdate_days`).
- **Administrator** u CMS-u. Odobrava ili odbija zahtjeve i može direktno upisati odsustvo.

**Odobreno odsustvo:**

- Sve planirane smjene radnika u tom periodu postaju **otvorene smjene**. Planer dobija obavještenje i u planeru bira zamjenu.
- Lista kandidata je provjerena: dostupni, upozorenje ili blokiran.
- Ako se odsustvo kasnije otkaže, otvorene smjene se **ne vraćaju** automatski. Planer odlučuje.

Bolovanje bilježi samo period. Dijagnoza se ne unosi.

**Godišnji odmor:**

- Broje se radni dani iz postavki (pon–pet), bez praznika.
- Pravo po godini dolazi iz ugovora ili iz odstupanja na radniku i može se uređivati u „Godišnji odmor – saldo".
- Neiskorišteni dani prelaze u sljedeću godinu. Troše se prvi i ističu nakon postavljenog roka (FBiH: 30.06.).
- Zahtjev koji premašuje saldo se ne može odobriti.

## Zamjene

**Kad radnik javi bolovanje**, administrator ima dvije opcije:

- upiše bolovanje, pa se smjene otvore same;
- u planeru na smjeni klikne „Otvori (odsutan)" i zatim „Dodijeli" zamjenu.

**Kad radnik sam traži zamjenu** u `/raspored`:

1. Bira kolegu. Nude se samo kolege koje nisu blokirane, a ⚠ znači upozorenje. Opcionalno bira i smjenu kolege za razmjenu.
2. Kolega prihvata ili odbija.
3. Planer odobrava u CMS-u (Raspored → Zamjene smjena). Pri odobrenju se sve provjere ponavljaju. Ako postoje upozorenja, napomena je obavezna.

**Šta se evidentira:**

- Na smjeni ostaje `OriginalMember`, tj. ko je prvobitno bio planiran.
- Ko je stvarno zbrinuo štićenika vidi se u osnovnom modulu (`DailyTask.CompletedBy`).

## Izvještaj o satima

`/raspored-izvjestaj/hours?m=2026-11&g=care`, uz `&format=csv` ili `&format=pdf`.

Kolone po radniku:

- broj smjena i sati (bez neplaćene pauze);
- noćni, nedjeljni i praznični sati, po stvarnom satu i DST-tačno. Kategorije se preklapaju;
- norma: ugovorni sati / 5 po radnom danu (pon–pet, bez praznika) dok je radnik zaposlen, umanjena za plaćena odsustva;
- razlika i dani odsustva po vrsti.

Procenti dodataka se prikazuju iz postavki, a obračun je u platnom sistemu.

CSV koristi `;` kao separator, decimalni zarez i UTF-8 BOM, tako da se ispravno otvara u Excelu za BiH i DACH.

Izvještaj se pravi iz **plana**. Evidencija stvarnog dolaska i odlaska nije dio modula. Ako se doda kao poseban modul, treba samo zamijeniti izvor sati u `HoursReportService`.

## Konfiguracija (app/_config/roster.yml)

```yaml
YouWillLikeIT\CareHome\Roster\Model\RosterSettings:
  default_preset: 'FBiH'        # samo pri prvom kreiranju postavki; 'DE' je dostupan
YouWillLikeIT\CareHome\Roster\Service\RosterSeeder:
  enabled: true
YouWillLikeIT\CareHome\Roster\Service\RosterNotifier:
  from_address: 'raspored@dom.local'
YouWillLikeIT\CareHome\Roster\Service\AbsenceService:
  sick_backdate_days: 3
YouWillLikeIT\CareHome\Roster\Service\RosterQuery:
  handover_window_minutes: 90   # primopredaja: ko počinje ±90 min oko kraja tekuće smjene
```

Vjerski praznici u FBiH nisu opći neradni dani. Unose se kao odsustvo vrste „Vjerski praznik", ili kao praznik (`PublicHoliday`, sa datumom za tu godinu) ako dom ne radi za sve.

## Testovi

```bash
vendor/bin/phpunit            # domenska logika: pravila, sati (DST), rotacije, kopiranje, saldo GO
```
