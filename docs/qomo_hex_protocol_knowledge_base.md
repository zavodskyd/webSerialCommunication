# QOMO Device HEX Protocol Knowledge Base

## Overview

The protocol uses fixed-length 3-byte HEX frames for transmitting device button events.

Example frames:

```text
2081a1
2091b1
3187b6
3486b2
3585b0
```

Typical serial communication settings:

```text
28800 baud
8 data bits
1 stop bit
no parity
```

---

# Frame Structure

Each message consists of 3 bytes:

```text
[B1][B2][B3]
```

---

# Byte Definitions

## Byte 1 (B1)

`B1` stores the high part of the device number as an offset from `0x20`.

Structure:

```text
B1 = 0x20 + floor(deviceId / 16)
```

This is important because devices can exceed `255`.

Do **not** decode `B1` using only `B1 & 0x0F`.  
That would only work for a limited range and fails for devices above `255`.

Examples:

| Device ID | floor(deviceId / 16) | B1 |
|---:|---:|---:|
| 1 | 0 | 0x20 |
| 16 | 1 | 0x21 |
| 100 | 6 | 0x26 |
| 211 | 13 | 0x2D |
| 279 | 17 | 0x31 |
| 313 | 19 | 0x33 |
| 326 | 20 | 0x34 |
| 341 | 21 | 0x35 |

---

## Byte 2 (B2)

`B2` contains both the button and the low part of the device number.

Structure:

```text
B2 = buttonPrefix | (deviceId % 16)
```

Meaning:

- upper 4 bits = button identifier
- lower 4 bits = device number remainder modulo 16

---

# Button Mapping

| Button | Prefix |
|---|---:|
| A | 0x80 |
| B | 0x90 |
| C | 0xA0 |
| D | 0xB0 |
| E | 0xC0 |
| F | 0xD0 |
| Ruka | 0xE0 |

---

## Byte 3 (B3)

`B3` is a checksum byte.

Structure:

```text
B3 = B1 XOR B2
```

A frame is valid only when:

```text
(B1 XOR B2) == B3
```

---

# Encoding

To generate a 3-byte frame from a device number and button:

```text
B1 = 0x20 + floor(deviceId / 16)
B2 = buttonPrefix | (deviceId % 16)
B3 = B1 XOR B2
```

Final frame:

```text
[B1][B2][B3]
```

---

# Decoding

To decode a received 3-byte frame:

```text
deviceId = ((B1 - 0x20) << 4) | (B2 & 0x0F)
buttonPrefix = B2 & 0xF0
valid = (B1 XOR B2) == B3
```

Equivalent form:

```text
deviceId = ((B1 - 0x20) * 16) + (B2 & 0x0F)
```

---

# Examples

## Device 1, Button A

Frame:

```text
20 81 A1
```

Encoding:

```text
B1 = 0x20 + floor(1 / 16) = 0x20
B2 = 0x80 | (1 % 16) = 0x81
B3 = 0x20 XOR 0x81 = 0xA1
```

Decoded result:

```text
deviceId = 1
button = A
```

---

## Device 1, Button B

Frame:

```text
20 91 B1
```

Decoded result:

```text
deviceId = 1
button = B
```

---

## Device 211, Button C

Frame:

```text
2D A3 8E
```

Validation:

```text
0x2D XOR 0xA3 = 0x8E
```

Decoding:

```text
deviceId = ((0x2D - 0x20) << 4) | (0xA3 & 0x0F)
deviceId = (0x0D << 4) | 0x03
deviceId = 211
button = C
```

---

## Device 279, Button A

Frame:

```text
31 87 B6
```

Validation:

```text
0x31 XOR 0x87 = 0xB6
```

Decoding:

```text
deviceId = ((0x31 - 0x20) << 4) | (0x87 & 0x0F)
deviceId = (0x11 << 4) | 0x07
deviceId = 279
button = A
```

---

## Device 326, Button A

Frame:

```text
34 86 B2
```

Validation:

```text
0x34 XOR 0x86 = 0xB2
```

Decoding:

```text
deviceId = ((0x34 - 0x20) << 4) | (0x86 & 0x0F)
deviceId = (0x14 << 4) | 0x06
deviceId = 326
button = A
```

---

## Device 341, Button C

Frame:

```text
35 A5 90
```

Validation:

```text
0x35 XOR 0xA5 = 0x90
```

Decoding:

```text
deviceId = ((0x35 - 0x20) << 4) | (0xA5 & 0x0F)
deviceId = (0x15 << 4) | 0x05
deviceId = 341
button = C
```

---

# Known Pitfall

This formula is incorrect for devices above 255:

```text
deviceId = ((B1 & 0x0F) << 4) | (B2 & 0x0F)
```

It incorrectly discards the higher range information stored in `B1`.

Correct formula:

```text
deviceId = ((B1 - 0x20) << 4) | (B2 & 0x0F)
```

---

# Protocol Characteristics

- fixed-length 3-byte frames
- deterministic encoding and decoding
- simple XOR checksum
- no full lookup table required
- supports at least device IDs `1..341` using the observed data range
- device number and button can be reconstructed algorithmically from each valid frame
