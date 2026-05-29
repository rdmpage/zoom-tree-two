# attic/

Code kept around for reference but no longer wired into the live pipeline.

## v1 labelling — superseded by write/transform/read

The v1 approach packed everything into one CLI (`label.php`) with flags for
token positions, composite keys, stop-at-pipe, TSV-driven matching, strict
vs loose monophyly, and so on.  It worked for simple cases (gekkota,
treeoflife) but each new real-world dataset required another flag, and the
combined CLI surface got hard to reason about.

The replacement design is three smaller scripts:

```
write_labels.php  tree.nwk  > labels.tsv     # dump every leaf + internal label
transform_X.php   labels.tsv > mapped.tsv    # tree-specific: cleaned label + lineage
read_labels.php   mapped.tsv tree.nwk        # apply new labels; lineage drives LCA inference
```

Each `transform_*.php` script knows about *one* labelling convention
(`Genus species`, `Order_Family_Genus_species_id`,
`Family_Genus_sp__informalID|haplo|locality`, …) and can be a few dozen
lines of regex.

### Files

- `label.php` — auto / --from-tsv / --token / --key-tokens / --key-from /
  --strict modes; loose-monophyly default.
- `prune-labels.php` — `--keep=N1,N2,...` and `--keep-from=N` leaf-label
  trimmers.  The transform scripts handle leaf cleaning now.
- `parse-squamata.php` — Pyron et al. 2013 classification → 2-column TSV
  (`taxon`, `members`).  Useful as a worked example when the new
  transform pipeline lands; the structural parsing can be lifted into a
  Squamata-specific transform.
