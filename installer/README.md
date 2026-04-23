# PMU Installer

Ce dossier contient l'installateur Windows du projet PMU.

## Fichiers

- `install-pmu.ps1` : installateur principal
- `install-pmu.cmd` : lanceur Windows simple
- `exe/Program.cs` : wrapper de lancement
- `build-exe.ps1` : génération du binaire via `csc.exe`

## Usage

Depuis PowerShell :

```powershell
.\installer\install-pmu.ps1
```

Pour générer l'exe :

```powershell
.\installer\build-exe.ps1
```

Avec les données locales du poste courant :

```powershell
.\installer\install-pmu.ps1 -IncludeLocalData
```

Avec un raccourci bureau :

```powershell
.\installer\install-pmu.ps1 -CreateDesktopShortcut
```

## Comportement

- clone ou met à jour le dépôt Git
- crée les dossiers `data`, `logs` et `exports`
- peut recopier les données locales si demandé
- peut créer un raccourci de bureau vers le dashboard
