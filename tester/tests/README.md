# Unified SOLtest Suite

This directory contains one unified test suite for `python/tester`.

- `NN-...` files are the imported full manual suite.
- `mNN-...` and `xNN-...` files are the focused submission suite cases.
- `specNN-...` files are additional tests adapted from examples in `sol26-spec`.

Run with:

```bash
docker run --rm -it -v "$PWD:/work" -w /work sol26-tester /work/python/tester/tests
```
