{{--
    Signature with the finger (PPE deliveries): the same drawing as the Campo's customer signature, with the canvas
    sized to the screen (sharp on any phone) and the drawing sent as a PNG to the form field.
    No double quotes inside x-data comments (they would cut the attribute).
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            drawing: false,
            used: false,
            ctx: null,
            ratio: 1,
            setup() {
                const pad = this.$refs.pad;
                const box = pad.getBoundingClientRect();
                this.ratio = window.devicePixelRatio || 1;
                pad.width = Math.max(1, Math.round(box.width * this.ratio));
                pad.height = Math.max(1, Math.round(box.height * this.ratio));
                this.ctx = pad.getContext('2d');
                this.ctx.setTransform(this.ratio, 0, 0, this.ratio, 0, 0);
                this.ctx.lineWidth = 2.6;
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.ctx.strokeStyle = '#111827';
            },
            point(event) {
                const box = this.$refs.pad.getBoundingClientRect();
                return [event.clientX - box.left, event.clientY - box.top];
            },
            start(event) {
                const pad = this.$refs.pad;
                // The modal may open after this field: size the canvas on the first touch.
                if (! this.used && pad.width !== Math.round(pad.getBoundingClientRect().width * (window.devicePixelRatio || 1))) { this.setup(); }
                if (! this.ctx) { this.setup(); }
                this.drawing = true;
                pad.setPointerCapture(event.pointerId);
                const [x, y] = this.point(event);
                this.ctx.beginPath();
                this.ctx.moveTo(x, y);
                this.ctx.lineTo(x + 0.1, y + 0.1);
                this.ctx.stroke();
            },
            move(event) {
                if (! this.drawing) { return; }
                const [x, y] = this.point(event);
                this.ctx.lineTo(x, y);
                this.ctx.stroke();
                this.used = true;
            },
            end() {
                if (! this.drawing) { return; }
                this.drawing = false;
                if (this.used) { this.state = this.$refs.pad.toDataURL('image/png'); }
            },
            clear() {
                const pad = this.$refs.pad;
                if (this.ctx) {
                    this.ctx.save();
                    this.ctx.setTransform(1, 0, 0, 1, 0, 0);
                    this.ctx.clearRect(0, 0, pad.width, pad.height);
                    this.ctx.restore();
                }
                this.used = false;
                this.state = null;
            },
        }"
        x-init="$nextTick(() => setup())"
    >
        <div style="position:relative">
            <canvas
                x-ref="pad"
                style="display:block;width:100%;height:190px;touch-action:none;background:#fff;border:1.5px dashed #9ca3af;border-radius:12px;cursor:crosshair"
                x-on:pointerdown.prevent="start($event)"
                x-on:pointermove.prevent="move($event)"
                x-on:pointerup="end()"
                x-on:pointercancel="end()"
            ></canvas>
            <div x-show="! used" style="position:absolute;left:16px;right:16px;bottom:34px;border-bottom:1px solid #d1d5db;pointer-events:none"></div>
            <div x-show="! used" style="position:absolute;left:0;right:0;bottom:12px;text-align:center;font-size:12px;color:#9ca3af;pointer-events:none">{{ __('epi.sign.pad_hint') }}</div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:6px">
            <x-filament::button color="gray" size="xs" icon="heroicon-o-arrow-path" x-on:click="clear()">{{ __('epi.sign.clear') }}</x-filament::button>
        </div>
    </div>
</x-dynamic-component>
