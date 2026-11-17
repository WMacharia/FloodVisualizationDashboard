# Legacy pipeline (not used)

These scripts belong to the project's first approach and are kept only as a record. Nothing in the
current system runs them or reads their outputs.

**What the first approach did.** Models were trained to predict flood labels that were either the FastFlood
hazard map (`01_download_data_original.py`, `02_preprocess.py`) or a synthetic label made from TWI and flow
accumulation (`02_twi_and_alignment_original.py`). The models were Random Forest / XGBoost regressions
(`06_*`, `07_*`) and a U-Net on CHIRPS rainfall patches (`03_*`–`06_run_inference.py`).

**Why it was replaced.**
- The synthetic label was computed from the same terrain inputs the model was given, so the model could
  only learn the formula back.
- The FastFlood map is a single hazard picture with classes, not depths, so it cannot show how flooding
  changes with storm size.
- The "spatial" split sorted file names rather than locations.

**Current pipeline** (in `scripts/`): `08_simulate_flood_scenarios.py` simulates 28 design storms,
`09_build_surrogate_dataset.py` builds the training set, `Flood_Surrogate_Training.ipynb` trains the
U-Net to reproduce the simulator, and `model_api.py` serves it.

`01_download_data_original.py` and `02_twi_and_alignment_original.py` are the unedited originals. The active
copies keep only the parts the current pipeline uses: building footprints, and the 30 m master grid
(now `02_align_master_grid.py`).
