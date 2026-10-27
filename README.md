# 📄 Kontratat e Studentëve – CIT

Program që **plotëson automatikisht kontratat Word** të regjistrimit të studentëve në CIT – për studentë vendas dhe ndërkombëtarë, Bachelor dhe Master.

> **EN:** A portable Windows tool that auto-fills CIT student enrollment contracts (Word) from a simple web form. No install, no internet – runs from a USB stick.

![Windows](https://img.shields.io/badge/Windows-10%20%7C%2011-0078D6?logo=windows)
![PHP](https://img.shields.io/badge/PHP-portable-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-database-003B57?logo=sqlite)
![Offline](https://img.shields.io/badge/internet-nuk%20nevojitet-success)

---

## ✨ Çfarë bën

| | |
|---|---|
| 📝 **4 lloje kontratash** | Vendas Bachelor · Vendas Master · Ndërkombëtar Bachelor (SQ+EN) · Ndërkombëtar Master (SQ+EN) |
| ⚡ **Plotësim automatik** | Email nga emri, tekst sipas gjinisë (*Studenti/Studentja*, *son/daughter*), tarifa sipas programit, zbritja në %, shuma me fjalë (shqip + anglisht) |
| 👨‍👩‍👧 **Studentë nën 18 vjeç** | Hapet vetë pjesa e prindit / përfaqësuesit ligjor |
| 🖨 **Printim** | Kontrata (2 kopje) + dokumente shtesë: Letër pranimi, Vërtetim regjistrimi, Fletë pagese |
| 💶 **Vërtetim pagese** | Për studentët ndërkombëtarë – pagesë e plotë ose e pjesshme |
| 📋 **Lista e studentëve** | Kërkim, ndryshim, fshirje, eksport në **Excel** |
| 🔁 **Sinkronizim me dosjen** | Lista lexohet vetë nga kontratat në dosjen `Kontratat` – edhe në kompjuter tjetër |

## 🚀 Si hapet

1. Shkarkoni / kopjoni dosjen **`Kontratat_Studenteve`** (ose `Kontratat_Studenteve.zip`) – p.sh. në USB.
2. Klikoni dy herë **`START.bat`**.
3. Hapet shfletuesi në `http://127.0.0.1:8766/`. **Mos e mbyllni dritaren e zezë** gjatë punës.

> Nëse Windows thotë *"Windows protected your PC"* → **More info** → **Run anyway**.
> Për printim nevojitet **Microsoft Word** në kompjuter.

## 🧑‍💻 Regjistrimi i një studenti

1. Zgjidhni **Vendas / Ndërkombëtar**, **Bachelor / Master** dhe **Raundin**.
2. Plotësoni të dhënat (`Enter` = fusha tjetër).
3. **💾 Ruaj kontratën** (`Ctrl + Enter`) → *Të printohet?* → **🖨 Po** (`Enter`) ose **Jo** (`Esc`).

Fushat bosh mbeten `____________` në kontratë, për t'u plotësuar me dorë.

## 📁 Struktura e projektit

```
Kontratat_Studenteve/
├── START.bat              ← hapni këtë
├── LEXO-MUA.txt           ← udhëzuesi i plotë
├── Kontratat/             ← kontratat e krijuara (sipas vitit / llojit / nivelit)
│   └── _cilesimet_e_programit.json
├── app/                   ← aplikacioni (PHP)
│   ├── templates/         ← modelet Word të kontratave
│   │   └── dokumente/     ← modelet e dokumenteve shtesë
│   └── data/kontratat.sqlite
└── php/                   ← PHP portable (s'ka nevojë për XAMPP)

Vendas/          ← kontratat origjinale (vendas)
Internacional/   ← kontratat origjinale (ndërkombëtare)
Prezantimi_Kontratat_Studenteve.pptx
```

## ✏️ Ndryshimi i modeleve

Modelet janë në `app/templates/`:
`vendas_bachelor.docx` · `vendas_master.docx` · `nderkombetar_bachelor.docx` · `nderkombetar_master.docx`

Hapini në Word dhe ndryshoni tekstin. **Mos i fshini fushat `{{...}}`** – programi i zëvendëson, p.sh.:

`{{EMRI_PLOTE}}` `{{STUDENTI_JA}}` `{{PROGRAMI}}` `{{TARIFA}}` `{{TARIFA_FJALE}}` `{{DATA}}` `{{VITI_AKADEMIK}}` …

Lista e plotë e fushave është te [`LEXO-MUA.txt`](Kontratat_Studenteve/LEXO-MUA.txt).

## 💾 Backup & ndërrimi i kompjuterit

- Kopjoni **gjithë** dosjen `Kontratat_Studenteve` (ose të paktën `app/data` dhe `Kontratat`).
- Mjafton edhe vetëm dosja `Kontratat`: programi i rilexon studentët nga kontratat dhe cilësimet nga `_cilesimet_e_programit.json`.

## 🛠 Probleme

| Problemi | Zgjidhja |
|---|---|
| *"Kontrata është e hapur në Word"* | Mbyllni dokumentin në Word dhe provoni përsëri |
| Faqja nuk hapet | Mbyllni dritaren e zezë dhe hapni përsëri `START.bat` |
| Humbje të dhënash | Mos e hiqni USB-në ndërsa programi është i hapur |

---

📖 Udhëzuesi i plotë: [`Kontratat_Studenteve/LEXO-MUA.txt`](Kontratat_Studenteve/LEXO-MUA.txt)
