<div>
    <div x-data="serialCommunication()" x-init="init()">
        <div class="flex items-center mt-4">
            <h1>Sériová komunikácia</h1>
            <div :class="isConnected ? 'bg-green-500' : 'bg-red-500'"
                class="w-4 h-4 ml-2 transition-colors duration-300 rounded-full"></div>
            <span class="ml-2" x-text="isConnected ? 'Pripojené' : 'Odpojené'"></span>
        </div>
        <x-primary-button @click="connect" x-bind:disabled="isConnected">Pripojiť</x-primary-button>
        <x-secondary-button @click="startCommunication" x-bind:disabled="!isConnected"
            x-bind:class="isReading ? '!border-green-600 !bg-green-500 !text-white shadow-sm shadow-green-500/30' : ''">Začať
            komunikáciu</x-secondary-button>
        <x-secondary-button @click="stopCommunication" x-bind:disabled="!isConnected">Zastaviť
            komunikáciu</x-secondary-button>
        <x-secondary-button @click="closeConnection" x-bind:disabled="!isConnected">Odpojiť</x-secondary-button>
        <x-secondary-button @click="showUsbDebug = !showUsbDebug"
            x-bind:class="showUsbDebug ? '!border-amber-300 !bg-amber-50 !text-amber-900' : ''">USB debug</x-secondary-button>
        <x-secondary-button @click="resetReceivedData" x-bind:disabled="!hasActiveRows()">Clear</x-secondary-button>

        <div class="mt-4">
            <h3>Prijaté dáta:</h3>
            <div class="mt-3 space-y-3">
                <div x-show="showUsbDebug" x-transition.opacity.duration.200ms
                    class="rounded-xl border border-amber-200/80 bg-amber-50/80 p-3 text-xs text-amber-900 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-semibold">USB debug</span>
                            <span x-text="`Raw chunky: ${rawChunkCount}`"></span>
                            <span x-text="`Rozpoznané frame: ${parsedFrameCount}`"></span>
                            <span x-text="`Neznáme frame: ${unknownFrameCount}`"></span>
                            <span x-text="`Zahodené bajty: ${droppedByteCount}`"></span>
                            <span x-text="`Buffer: ${incomingBytes.length}`"></span>
                        </div>
                        <x-secondary-button @click="clearDebugLog" x-bind:disabled="debugEvents.length === 0">Clear debug</x-secondary-button>
                    </div>
                    <div class="mt-3 max-h-40 overflow-auto rounded-lg border border-amber-200/70 bg-white/70 p-2 font-mono text-[11px]">
                        <template x-if="debugEvents.length === 0">
                            <div class="text-slate-500">Zatial bez raw USB dat.</div>
                        </template>
                        <template x-for="(event, index) in debugEvents" :key="`${event.timestamp}-${index}`">
                            <div class="border-b border-slate-200/60 py-1 last:border-b-0">
                                <span class="font-semibold" x-text="event.timestamp"></span>
                                <span class="ml-2 uppercase tracking-wide text-slate-500" x-text="event.type"></span>
                                <span class="ml-2" x-text="event.message"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-300/70 bg-white/80 shadow-sm">
                    <div class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/95 px-3 py-2 backdrop-blur">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold">Názov zariadenia:</span>
                            <span class="rounded-full border border-slate-300/70 px-2.5 py-1 font-mono"
                                x-text="lastMatchedDeviceNumber || '-'"></span>
                            <template x-if="lastButtonName">
                                <span class="text-slate-600" x-text="`Posledné tlačidlo: ${lastButtonName}`"></span>
                            </template>
                        </div>
                    </div>

                    <div class="max-h-[70vh] overflow-auto">
                        <table class="min-w-full divide-y divide-slate-200/80 text-xs">
                            <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur">
                                <tr class="text-left uppercase tracking-[0.18em] text-slate-500">
                                    <th class="px-3 py-2 font-semibold">Číslo zariadenia</th>
                                    <th class="px-2 py-2 font-semibold">A</th>
                                    <th class="px-2 py-2 font-semibold">B</th>
                                    <th class="px-2 py-2 font-semibold">C</th>
                                    <th class="px-2 py-2 font-semibold">D</th>
                                    <th class="px-2 py-2 font-semibold">E</th>
                                    <th class="px-2 py-2 font-semibold">F</th>
                                    <th class="px-2 py-2 font-semibold">Ruka</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200/70 bg-white/60">
                                @foreach ($devices as $device)
                                    <tr wire:key="device-row-{{ $device->id }}"
                                        x-show="shouldShowRow('{{ $device->device_number }}')"
                                        x-ref="deviceRow{{ $device->device_number }}"
                                        x-transition.opacity.duration.200ms
                                        class="text-slate-700">
                                        <td class="px-3 py-2 font-mono text-[11px] font-semibold">{{ $device->device_number }}</td>
                                        @foreach (['A', 'B', 'C', 'D', 'E', 'F', 'Ruka'] as $button)
                                            <td class="px-2 py-1.5">
                                                <div class="flex justify-center">
                                                    <span
                                                        class="inline-flex h-7 w-7 items-center justify-center rounded-full border text-[10px] font-semibold transition-all duration-200"
                                                        :class="getIndicatorClasses('{{ $device->device_number }}', '{{ $button }}')"
                                                        x-text="getButtonCount('{{ $device->device_number }}', '{{ $button }}') || ''"></span>
                                                </div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function serialCommunication() {
        return {
            unsupportedApiNoticeKey: `serial-api-unsupported-session-{{ session()->getId() }}`,
            baudRate: 28800,
            dataBits: 8,
            parity: 'none',
            stopBits: 1,
            flowControl: 'none',
            receivedData: '',
            isConnected: false,
            serialPort: null,
            reader: null,
            writer: null,
            isReading: false,
            lastMatchedDeviceNumber: null,
            lastButtonName: null,
            messageByteLength: 3,
            incomingBytes: [],
            codeLookup: {{ Illuminate\Support\Js::from($codeLookup) }},
            codePrefixes: {{ Illuminate\Support\Js::from($codePrefixes) }},
            deviceButtonCounts: {},
            droppedByteCount: 0,
            unknownFrameCount: 0,
            rawChunkCount: 0,
            parsedFrameCount: 0,
            debugEvents: [],
            showUsbDebug: false,

            init() {
                if ('serial' in navigator) {
                    console.log('Web Serial API is supported');
                    this.closeConnection();
                } else {
                    console.error('Web Serial API not supported in this browser.');
                    this.showUnsupportedApiNoticeOncePerLogin();
                }
            },

            showUnsupportedApiNoticeOncePerLogin() {
                try {
                    if (sessionStorage.getItem(this.unsupportedApiNoticeKey)) {
                        return;
                    }

                    sessionStorage.setItem(this.unsupportedApiNoticeKey, '1');
                } catch (error) {
                    console.warn('Unable to persist Web Serial API notice state:', error);
                }

                alert('Web Serial API not supported in this browser.');
            },

            async connect() {
                try {
                    console.log('Attempting to connect...');
                    this.serialPort = await navigator.serial.requestPort();
                    await this.serialPort.open({
                        baudRate: this.baudRate,
                        dataBits: this.dataBits,
                        parity: this.parity,
                        stopBits: this.stopBits,
                        flowControl: this.flowControl
                    });
                    this.isConnected = true;
                    console.log('Connected successfully');

                    this.writer = this.serialPort.writable.getWriter();
                    console.log('Writer created');

                    await this.initializeDevice();
                } catch (error) {
                    console.error('Failed to connect:', error);
                }
            },

            async initializeDevice() {
                try {
                    console.log('Initializing device...');
                    await this.sendHexCommand("f400c00236", 5);
                    await this.sendHexCommand("f500000101f5", 6);
                    await this.sendHexCommand("f54b4e050200000601f0", 10);
                    console.log("Device initialized");
                } catch (error) {
                    console.error('Failed to initialize device:', error);
                }
            },

            async startCommunication() {
                try {
                    console.log('Starting communication...');
                    await this.sendHexCommand("5b80db", 3);
                    await this.sendHexCommand("5a80da", 3);
                    console.log("Communication started");

                    this.resetReceivedData();
                    this.isReading = true;
                    this.readData();
                } catch (error) {
                    console.error('Failed to start communication:', error);
                }
            },

            async stopCommunication() {
                try {
                    console.log('Stopping communication...');
                    await this.sendHexCommand("5b80db", 3);
                    console.log("Communication stop command sent");
                    this.isReading = false;
                    if (this.reader) {
                        try {
                            await this.reader.cancel();
                            console.log("Reader cancelled");
                        } catch (error) {
                            console.log("Reader already cancelled or released");
                        }
                        this.reader = null;
                    }
                } catch (error) {
                    console.error('Failed to stop communication:', error);
                }
            },

            async readData() {
                console.log('Starting to read data...');

                try {
                    while (this.isReading) {
                        this.reader = this.serialPort.readable.getReader();
                        try {
                            while (this.isReading) {
                                const {
                                    value,
                                    done
                                } = await this.reader.read();
                                if (done) {
                                    console.log('Reading finished');
                                    break;
                                }
                                this.queueIncomingBytes(value);
                                this.processIncomingMessages();
                            }
                        } finally {
                            this.reader.releaseLock();
                            this.reader = null;
                            console.log('Reader released');
                        }
                    }
                } catch (error) {
                    console.error('Error reading data:', error);
                }

                console.log('Stopped reading data');
            },

            resetReceivedData() {
                this.receivedData = '';
                this.lastMatchedDeviceNumber = null;
                this.lastButtonName = null;
                this.incomingBytes = [];
                this.deviceButtonCounts = {};
                this.rawChunkCount = 0;
                this.parsedFrameCount = 0;
                this.droppedByteCount = 0;
                this.unknownFrameCount = 0;
                this.debugEvents = [];
            },

            queueIncomingBytes(buffer) {
                const bytes = Array.from(buffer);

                this.rawChunkCount += 1;
                this.incomingBytes.push(...bytes);
                this.recordDebugEvent('raw', `chunk=${this.byteArrayToHex(bytes)} len=${bytes.length}`);
            },

            processIncomingMessages() {
                while (this.incomingBytes.length >= this.messageByteLength) {
                    const frame = this.incomingBytes.slice(0, this.messageByteLength);
                    const hexData = this.byteArrayToHex(frame);
                    const matchedCode = this.codeLookup[hexData] || null;

                    if (!matchedCode) {
                        this.resyncIncomingBytes();
                        continue;
                    }

                    this.incomingBytes.splice(0, this.messageByteLength);
                    this.parsedFrameCount += 1;
                    this.recordDebugEvent('parsed', `frame=${hexData} device=${matchedCode.deviceNumber} button=${matchedCode.buttonName}`);
                    this.lastMatchedDeviceNumber = matchedCode.deviceNumber;
                    this.lastButtonName = matchedCode.buttonName;
                    this.incrementButtonCount(matchedCode.deviceNumber, matchedCode.buttonName);
                }
            },

            resyncIncomingBytes() {
                if (this.incomingBytes.length < this.messageByteLength) {
                    return;
                }

                const frame = this.incomingBytes.slice(0, this.messageByteLength);
                const oneBytePrefix = this.byteArrayToHex(frame.slice(0, 1));
                const twoBytePrefix = this.byteArrayToHex(frame.slice(0, 2));

                this.unknownFrameCount += 1;
                this.recordDebugEvent('unknown', `frame=${this.byteArrayToHex(frame)}`);

                const couldBeStartOfKnownFrame = this.codePrefixes.oneByte.includes(oneBytePrefix) ||
                    this.codePrefixes.twoBytes.includes(twoBytePrefix);

                this.incomingBytes.shift();
                this.droppedByteCount += 1;

                if (couldBeStartOfKnownFrame) {
                    this.recordDebugEvent('resync', 'potential misalignment, shifted by 1 byte');
                }
            },

            clearDebugLog() {
                this.rawChunkCount = 0;
                this.parsedFrameCount = 0;
                this.droppedByteCount = 0;
                this.unknownFrameCount = 0;
                this.debugEvents = [];
            },

            incrementButtonCount(deviceNumber, buttonName) {
                if (!this.deviceButtonCounts[deviceNumber]) {
                    this.deviceButtonCounts[deviceNumber] = {};
                }

                const currentCount = this.deviceButtonCounts[deviceNumber][buttonName] || 0;
                this.deviceButtonCounts[deviceNumber][buttonName] = currentCount + 1;
                this.scrollToActiveRow(deviceNumber);
            },

            getButtonCount(deviceNumber, buttonName) {
                return this.deviceButtonCounts[deviceNumber]?.[buttonName] || 0;
            },

            shouldShowRow(deviceNumber) {
                return Object.keys(this.deviceButtonCounts[deviceNumber] || {}).length > 0;
            },

            hasActiveRows() {
                return Object.keys(this.deviceButtonCounts).some((deviceNumber) => this.shouldShowRow(deviceNumber));
            },

            getIndicatorClasses(deviceNumber, buttonName) {
                return this.getButtonCount(deviceNumber, buttonName) > 0 ?
                    'border-green-600 bg-green-500 text-white shadow-sm shadow-green-500/30' :
                    'border-slate-300 bg-slate-100 text-slate-400';
            },

            scrollToActiveRow(deviceNumber) {
                this.$nextTick(() => {
                    const row = this.$refs[`deviceRow${deviceNumber}`];

                    if (row) {
                        row.scrollIntoView({
                            behavior: 'smooth',
                            block: 'nearest',
                        });
                    }
                });
            },

            async sendHexCommand(hexString, length) {
                if (this.serialPort && this.isConnected && this.writer) {
                    const bytes = this.hexToByte(hexString);
                    console.log(`Sending command: ${hexString}, length: ${length}`);
                    console.log('Bytes being sent (decimal):', Array.from(bytes.slice(0, length)));
                    console.log('Bytes being sent (hex):', Array.from(bytes.slice(0, length)).map(b => b.toString(
                        16).padStart(2, '0')).join(' '));

                    await this.writer.write(bytes.slice(0, length));

                    console.log(`Command sent: ${hexString}`);
                } else {
                    console.error("Serial port is not open or connected");
                }
            },

            hexToByte(msg) {
                // Odstránime všetky medzery z reťazca
                msg = msg.replace(/\s/g, '');

                // Vytvoríme Uint8Array s dĺžkou reťazca delenou 2
                let comBuffer = new Uint8Array(msg.length / 2);

                // Prechádzame cez dĺžku poskytnutého reťazca
                for (let i = 0; i < msg.length; i += 2) {
                    // Konvertujeme každú dvojicu znakov na desiatkovú hodnotu
                    comBuffer[i / 2] = parseInt(msg.substr(i, 2), 16);
                }

                // Vrátime pole
                return comBuffer;
            },

            arrayBufferToHex(buffer) {
                return Array.from(new Uint8Array(buffer))
                    .map(b => b.toString(16).padStart(2, '0'))
                    .join('');
            },

            byteArrayToHex(bytes) {
                return bytes
                    .map((byte) => byte.toString(16).padStart(2, '0'))
                    .join('');
            },

            recordDebugEvent(type, message) {
                const timestamp = new Date().toISOString().slice(11, 23);
                const event = {
                    timestamp,
                    type,
                    message,
                };

                this.debugEvents.unshift(event);
                this.debugEvents = this.debugEvents.slice(0, 40);

                console.log(`[serial-debug][${type}] ${timestamp} ${message}`);
            },

            async closeConnection() {
                console.log('Closing connection...');
                if (this.isReading) {
                    this.stopCommunication();
                }
                this.isReading = false;
                if (this.reader) {
                    try {
                        await this.reader.cancel();
                        console.log('Reader cancelled');
                    } catch (error) {
                        console.log("Reader already cancelled or released");
                    }
                    this.reader = null;
                }
                if (this.writer) {
                    try {
                        await this.writer.close();
                        console.log('Writer closed');
                    } catch (error) {
                        console.log("Writer already closed");
                    }
                    this.writer = null;
                }
                if (this.serialPort) {
                    try {
                        await this.serialPort.close();
                        console.log('Serial port closed');
                    } catch (error) {
                        console.error("Error closing serial port:", error);
                    }
                    this.serialPort = null;
                }
                this.isConnected = false;
                console.log('Connection closed successfully');
            }
        }
    }
</script>
